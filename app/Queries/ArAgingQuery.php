<?php

declare(strict_types=1);

namespace App\Queries;

use App\Enums\InflowStatus;
use App\Models\Customer;
use App\Models\Inflow;
use App\Models\Order;
use App\Support\Money\CurrencyRegistry;
use App\Support\Money\Money;
use App\Tenancy\Contracts\TenantContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Accounts-receivable aging: outstanding (unpaid) order balances bucketed by how
 * long they've been owed (by the order's effective date), per customer and in
 * total. "Outstanding" = order total minus received inflows (credit notes
 * negative).
 */
class ArAgingQuery
{
    public function __construct(private readonly TenantContext $tenant) {}

    /**
     * @return array<string, mixed>
     */
    public function get(): array
    {
        $currency = $this->currency();

        // Per-order received total (credit notes negative) — the same
        // formula OrderPipelineQuery::paid() groups the same way.
        $paid = Inflow::query()
            ->select('order_id')
            ->whereNotNull('order_id')
            ->where('status', InflowStatus::Received->value)
            ->groupBy('order_id')
            ->selectRaw('SUM(CASE WHEN is_credit_note THEN -amount ELSE amount END) as paid');

        // Only orders with a genuine outstanding balance (total > paid) are
        // pulled into PHP — filtered here in SQL rather than loading every
        // non-consignment order the tenant has ever placed just to find the
        // handful still owed. leftJoinSub (not joinSub) so an order with no
        // Inflow at all still matches, with paid_minor coalescing to 0.
        $orders = Order::query()
            ->where('orders.is_consignment', false)
            ->leftJoinSub($paid, 'settled', 'settled.order_id', '=', 'orders.id')
            ->whereRaw('orders.total_amount > COALESCE(settled.paid, 0)')
            ->get([
                'orders.id', 'orders.order_number', 'orders.customer_id',
                'orders.created_at', 'orders.backorder_date', 'orders.total_amount',
                DB::raw('COALESCE(settled.paid, 0) as paid_minor'),
            ]);

        $now = Carbon::now();
        $buckets = ['current' => 0, 'd30' => 0, 'd60' => 0, 'd90_plus' => 0];
        $byCustomer = [];
        $totalOutstanding = 0;

        foreach ($orders as $order) {
            // Every row here already has a positive balance (the SQL WHERE
            // above guarantees it), so no re-check before using it.
            $balance = $order->total_amount->getMinorAmount() - (int) $order->getAttribute('paid_minor');

            $effective = $order->backorder_date ?? $order->created_at ?? $now;
            $age = $effective->diffInDays($now);
            $bucket = match (true) {
                $age <= 30 => 'current',
                $age <= 60 => 'd30',
                $age <= 90 => 'd60',
                default => 'd90_plus',
            };

            $buckets[$bucket] += $balance;
            $totalOutstanding += $balance;

            $cid = $order->customer_id;
            $byCustomer[$cid] ??= ['customer_id' => $cid, 'outstanding_minor' => 0, 'orders' => 0];
            $byCustomer[$cid]['outstanding_minor'] += $balance;
            $byCustomer[$cid]['orders']++;
        }

        $names = Customer::query()->whereIn('id', array_keys($byCustomer))->pluck('company_name', 'id');

        $customers = array_values(array_map(fn (array $row) => [
            'customer_id' => $row['customer_id'],
            'company_name' => $names[$row['customer_id']] ?? null,
            'orders' => $row['orders'],
            'outstanding' => Money::fromMinor($row['outstanding_minor'], $currency)->jsonSerialize(),
        ], $byCustomer));

        usort($customers, fn (array $a, array $b) => $b['outstanding']['minor'] <=> $a['outstanding']['minor']);

        return [
            'total_outstanding' => Money::fromMinor($totalOutstanding, $currency)->jsonSerialize(),
            'buckets' => [
                'current' => Money::fromMinor($buckets['current'], $currency)->jsonSerialize(),
                '31_60' => Money::fromMinor($buckets['d30'], $currency)->jsonSerialize(),
                '61_90' => Money::fromMinor($buckets['d60'], $currency)->jsonSerialize(),
                '90_plus' => Money::fromMinor($buckets['d90_plus'], $currency)->jsonSerialize(),
            ],
            'by_customer' => $customers,
        ];
    }

    private function currency(): string
    {
        $currency = $this->tenant->current()?->settings()->first()?->default_currency;

        return $currency ?? CurrencyRegistry::default()->code;
    }
}
