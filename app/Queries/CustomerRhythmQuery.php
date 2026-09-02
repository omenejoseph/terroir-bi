<?php

declare(strict_types=1);

namespace App\Queries;

use App\Models\Customer;
use App\Models\Order;
use App\Support\OrderCadence;
use Illuminate\Support\Carbon;

/**
 * The "Order rhythm" strip on a customer's overview (Figma 231:9336): every
 * order plotted on a timeline, the customer's typical gap, and how long the
 * current silence has run against it.
 *
 * The same OrderCadence helper the reorder radar uses decides what "typical"
 * means, so a customer flagged as overdue on the radar is overdue here too.
 */
class CustomerRhythmQuery
{
    /**
     * @return array{
     *     orders: list<array{date: string, position: float}>,
     *     from: string,
     *     to: string,
     *     median_gap_days: float|null,
     *     expected_next_date: string|null,
     *     expected_next_position: float|null,
     *     days_since_last: int|null,
     *     overdue: bool,
     * }
     */
    public function get(Customer $customer, int $months = 5): array
    {
        $to = Carbon::now()->endOfDay();
        $from = $to->copy()->subMonthsNoOverflow($months)->startOfDay();

        /** @var list<Carbon> $all */
        $all = Order::query()
            ->where('customer_id', $customer->getKey())
            ->orderBy('created_at')
            ->pluck('created_at')
            ->filter()
            ->map(fn (mixed $date): Carbon => Carbon::parse((string) $date))
            ->values()
            ->all();

        $median = count($all) >= 2 ? OrderCadence::medianGapDays($all) : null;
        $expected = OrderCadence::expectedNext($all);
        $last = $all === [] ? null : end($all);
        $daysSince = $last instanceof Carbon ? (int) $last->diffInDays($to) : null;

        $window = max(1.0, (float) $from->diffInDays($to));

        $plotted = [];
        foreach ($all as $date) {
            if ($date->lessThan($from)) {
                continue;
            }

            $plotted[] = [
                'date' => $date->toIso8601String(),
                'position' => round($from->diffInDays($date) / $window, 4),
            ];
        }

        return [
            'orders' => $plotted,
            'from' => $from->toIso8601String(),
            'to' => $to->toIso8601String(),
            'median_gap_days' => $median !== null ? round($median, 1) : null,
            'expected_next_date' => $expected?->toIso8601String(),
            // Null when the projection falls outside the plotted window, so the
            // marker is never drawn off the end of the strip.
            'expected_next_position' => $expected !== null && $expected->betweenIncluded($from, $to)
                ? round($from->diffInDays($expected) / $window, 4)
                : null,
            'days_since_last' => $daysSince,
            'overdue' => $median !== null && $daysSince !== null && $daysSince > $median,
        ];
    }
}
