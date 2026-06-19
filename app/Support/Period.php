<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Carbon;

/**
 * Resolves an analytics period to a [from, to] Carbon range. An explicit
 * from/to wins; otherwise a named preset is used (default: last 30 days).
 */
final class Period
{
    /**
     * @return array{0: Carbon, 1: Carbon}
     */
    public static function resolve(?string $preset, ?string $from = null, ?string $to = null): array
    {
        if ($from !== null || $to !== null) {
            return [
                Carbon::parse($from ?? '1970-01-01')->startOfDay(),
                Carbon::parse($to ?? 'now')->endOfDay(),
            ];
        }

        $now = Carbon::now();

        return match ($preset) {
            'today' => [$now->copy()->startOfDay(), $now->copy()->endOfDay()],
            'yesterday' => [$now->copy()->subDay()->startOfDay(), $now->copy()->subDay()->endOfDay()],
            'last_7', '7d' => [$now->copy()->subDays(7)->startOfDay(), $now->copy()->endOfDay()],
            'last_30', '30d' => [$now->copy()->subDays(30)->startOfDay(), $now->copy()->endOfDay()],
            'last_90', '90d' => [$now->copy()->subDays(90)->startOfDay(), $now->copy()->endOfDay()],
            '1y', 'last_12' => [$now->copy()->subDays(365)->startOfDay(), $now->copy()->endOfDay()],
            'mtd', 'this_month' => [$now->copy()->startOfMonth(), $now->copy()->endOfDay()],
            'qtd' => [$now->copy()->startOfQuarter(), $now->copy()->endOfDay()],
            'month' => [$now->copy()->startOfMonth(), $now->copy()->endOfMonth()],
            'last_month', 'last-month' => [
                $now->copy()->subMonthNoOverflow()->startOfMonth(),
                $now->copy()->subMonthNoOverflow()->endOfMonth(),
            ],
            'ytd' => [$now->copy()->startOfYear(), $now->copy()->endOfDay()],
            'last-year', 'last_year' => [
                $now->copy()->subYear()->startOfYear(),
                $now->copy()->subYear()->endOfYear(),
            ],
            'all' => [Carbon::parse('1970-01-01'), $now->copy()->endOfDay()],
            default => [$now->copy()->subDays(30)->startOfDay(), $now->copy()->endOfDay()],
        };
    }
}
