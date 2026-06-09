<?php

declare(strict_types=1);

namespace App\Queries;

use App\Models\Customer;
use App\Models\Order;
use App\Support\Money\CurrencyRegistry;
use App\Support\Money\Money;
use App\Tenancy\Contracts\TenantContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Churn radar: flags active customers who are overdue to reorder, based on the
 * median gap between their past orders. Accounts already contacted since their
 * last order are muted (reorder_contacted_at >= last order). Ranked by a
 * value-weighted urgency so big, slipping accounts surface first.
 */
class ReorderRadarQuery
{
    private const MIN_ORDERS = 3;

    public function __construct(private readonly TenantContext $tenant) {}

    /**
     * @return array{rows: list<array<string, mixed>>, counts: array{due: int, overdue: int, at_risk: int}}
     */
    public function get(): array
    {
        $currency = $this->currency();

        $customers = Customer::query()
            ->where('is_active', true)
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
            if ($list->count() < self::MIN_ORDERS) {
                continue;
            }

            $customer = $customers->get($customerId);
            if (! $customer instanceof Customer) {
                continue;
            }

            /** @var list<Carbon> $dates */
            $dates = $list->map(fn (Order $o) => $this->effectiveDate($o))->sort()->values()->all();
            $median = $this->medianGapDays($dates);
            $last = end($dates);
            if ($median <= 0 || ! $last instanceof Carbon) {
                continue;
            }

            $daysSince = $last->diffInDays(Carbon::now());
            $ratio = $daysSince / $median;

            $status = $this->status($ratio);
            if ($status === null) {
                continue;
            }

            // Muted once contacted since the last order (cleared on the next order).
            $contactedAt = $customer->reorder_contacted_at;
            if ($contactedAt !== null && $contactedAt->greaterThanOrEqualTo($last)) {
                continue;
            }

            $avgMinor = intdiv((int) $list->sum(fn (Order $o) => $o->total_amount->getMinorAmount()), $list->count());

            $rows[] = [
                'customer_id' => $customer->getKey(),
                'company_name' => $customer->company_name,
                'order_count' => $list->count(),
                'last_order_date' => $last->toIso8601String(),
                'days_since_last' => $daysSince,
                'median_gap_days' => round($median, 1),
                'overdue_ratio' => round($ratio, 2),
                'status' => $status,
                'avg_order_value' => Money::fromMinor($avgMinor, $currency)->jsonSerialize(),
                'urgency' => round(min($ratio, 6) * $avgMinor, 2),
            ];
        }

        usort($rows, fn (array $a, array $b) => $b['urgency'] <=> $a['urgency']);

        return [
            'rows' => $rows,
            'counts' => [
                'due' => count(array_filter($rows, fn (array $r) => $r['status'] === 'due')),
                'overdue' => count(array_filter($rows, fn (array $r) => $r['status'] === 'overdue')),
                'at_risk' => count(array_filter($rows, fn (array $r) => $r['status'] === 'at_risk')),
            ],
        ];
    }

    private function effectiveDate(Order $order): Carbon
    {
        return $order->backorder_date ?? $order->created_at ?? Carbon::now();
    }

    /**
     * @param  list<Carbon>  $dates  sorted ascending
     */
    private function medianGapDays(array $dates): float
    {
        $gaps = [];
        $prev = null;
        foreach ($dates as $date) {
            if ($prev !== null) {
                $gaps[] = (float) $prev->diffInDays($date);
            }
            $prev = $date;
        }

        if ($gaps === []) {
            return 0.0;
        }

        sort($gaps);
        $mid = intdiv(count($gaps), 2);

        return count($gaps) % 2 === 0
            ? ($gaps[$mid - 1] + $gaps[$mid]) / 2
            : $gaps[$mid];
    }

    private function status(float $ratio): ?string
    {
        return match (true) {
            $ratio >= 3.0 => 'at_risk',
            $ratio >= 1.75 => 'overdue',
            $ratio >= 1.0 => 'due',
            default => null,
        };
    }

    private function currency(): string
    {
        $currency = $this->tenant->current()?->settings()->first()?->default_currency;

        return $currency ?? CurrencyRegistry::default()->code;
    }
}
