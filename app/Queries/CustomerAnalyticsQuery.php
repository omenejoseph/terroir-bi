<?php

declare(strict_types=1);

namespace App\Queries;

use App\Models\Customer;
use App\Models\Order;
use App\Support\Money\CurrencyRegistry;
use App\Support\Money\Money;
use App\Support\OrderCadence;
use App\Tenancy\Contracts\TenantContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Tenant-wide customer analytics: headline totals plus a per-customer table of
 * revenue (12m + all-time), order cadence (median gap), last order, and the
 * projected next order. Reuses {@see OrderCadence} so the rhythm maths match the
 * per-customer page and the reorder radar. Consignment orders are excluded.
 */
class CustomerAnalyticsQuery
{
    public function __construct(private readonly TenantContext $tenant) {}

    /**
     * @return array<string, mixed>
     */
    public function get(): array
    {
        $currency = $this->currency();
        $from12 = Carbon::now()->subYear();

        $customers = Customer::query()
            ->where('exclude_from_stats', false)
            ->get()
            ->keyBy('id');

        /** @var Collection<int, Order> $orders */
        $orders = Order::query()
            ->where('is_consignment', false)
            ->whereIn('customer_id', $customers->keys())
            ->get(['customer_id', 'created_at', 'backorder_date', 'total_amount']);

        $rows = [];
        foreach ($orders->groupBy('customer_id') as $customerId => $list) {
            $customer = $customers->get($customerId);
            if (! $customer instanceof Customer) {
                continue;
            }

            /** @var list<Carbon> $dates */
            $dates = $list->map(fn (Order $o) => $this->effectiveDate($o))->sort()->values()->all();
            $last = end($dates);

            $allTime = (int) $list->sum(fn (Order $o) => $o->total_amount->getMinorAmount());
            $recent = $list->filter(fn (Order $o) => $this->effectiveDate($o)->greaterThanOrEqualTo($from12));
            $revenue12 = (int) $recent->sum(fn (Order $o) => $o->total_amount->getMinorAmount());
            $orders12 = $recent->count();
            $avg = $orders12 > 0 ? intdiv($revenue12, $orders12) : 0;
            $median = OrderCadence::medianGapDays($dates);
            $next = OrderCadence::expectedNext($dates);

            $rows[] = [
                'customer_id' => $customer->getKey(),
                'company_name' => $customer->company_name,
                'contact_name' => $customer->contact_name,
                'revenue_12m' => Money::fromMinor($revenue12, $currency)->jsonSerialize(),
                'revenue_all_time' => Money::fromMinor($allTime, $currency)->jsonSerialize(),
                'order_count_12m' => $orders12,
                'avg_order_value' => Money::fromMinor($avg, $currency)->jsonSerialize(),
                'last_order_date' => $last instanceof Carbon ? $last->toIso8601String() : null,
                'days_since_last_order' => $last instanceof Carbon ? (int) $last->diffInDays(Carbon::now()) : null,
                'median_gap_days' => count($dates) >= 2 ? round($median, 1) : null,
                'expected_next_order_date' => $next?->toIso8601String(),
                '_rev12' => $revenue12, // sort key (stripped below)
            ];
        }

        usort($rows, fn (array $a, array $b) => $b['_rev12'] <=> $a['_rev12']);

        $totalRevenue12 = array_sum(array_column($rows, '_rev12'));
        $top = $rows[0] ?? null;

        $customersOut = array_map(function (array $r): array {
            unset($r['_rev12']);

            return $r;
        }, $rows);

        return [
            'summary' => [
                'active_customers' => count($rows),
                'revenue_12m' => Money::fromMinor((int) $totalRevenue12, $currency)->jsonSerialize(),
                'top_customer' => $top !== null && $top['_rev12'] > 0 ? [
                    'id' => $top['customer_id'],
                    'company_name' => $top['company_name'],
                    'contact_name' => $top['contact_name'],
                    'revenue_12m' => $top['revenue_12m'],
                ] : null,
            ],
            'customers' => $customersOut,
        ];
    }

    private function effectiveDate(Order $order): Carbon
    {
        return $order->backorder_date ?? $order->created_at ?? Carbon::now();
    }

    private function currency(): string
    {
        $currency = $this->tenant->current()?->settings()->first()?->default_currency;

        return $currency ?? CurrencyRegistry::default()->code;
    }
}
