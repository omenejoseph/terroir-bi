<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Carbon;

/**
 * Order-rhythm helpers shared by the reorder radar and per-customer analytics:
 * the median gap between consecutive orders, and the projected next order date.
 */
final class OrderCadence
{
    /**
     * Median number of days between consecutive orders.
     *
     * @param  list<Carbon>  $dates  sorted ascending
     */
    public static function medianGapDays(array $dates): float
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

    /**
     * Projected next order date = last order + median gap. Null when there are
     * too few orders to establish a rhythm.
     *
     * @param  list<Carbon>  $dates  sorted ascending
     */
    public static function expectedNext(array $dates, int $minOrders = 3): ?Carbon
    {
        if (count($dates) < $minOrders) {
            return null;
        }

        $median = self::medianGapDays($dates);
        $last = end($dates);
        if ($median <= 0 || ! $last instanceof Carbon) {
            return null;
        }

        return $last->copy()->addDays((int) round($median));
    }
}
