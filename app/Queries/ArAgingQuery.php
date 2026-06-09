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

        $paidByOrder = Inflow::query()
            ->whereNotNull('order_id')
            ->where('status', InflowStatus::Received->value)
            ->groupBy('order_id')
            ->pluck(DB::raw('SUM(CASE WHEN is_credit_note THEN -amount ELSE amount END)'), 'order_id');

        $orders = Order::query()
            ->where('is_consignment', false)
            ->get(['id', 'order_number', 'customer_id', 'created_at', 'backorder_date', 'total_amount']);

        $now = Carbon::now();
        $buckets = ['current' => 0, 'd30' => 0, 'd60' => 0, 'd90_plus' => 0];
        $byCustomer = [];
        $totalOutstanding = 0;

        foreach ($orders as $order) {
            $paid = (int) ($paidByOrder[$order->getKey()] ?? 0);
            $balance = $order->total_amount->getMinorAmount() - $paid;
            if ($balance <= 0) {
                continue;
            }

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
