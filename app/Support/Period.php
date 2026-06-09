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
            'last_7' => [$now->copy()->subDays(7)->startOfDay(), $now->copy()->endOfDay()],
            'last_90' => [$now->copy()->subDays(90)->startOfDay(), $now->copy()->endOfDay()],
            'mtd', 'this_month' => [$now->copy()->startOfMonth(), $now->copy()->endOfDay()],
            'last_month' => [
                $now->copy()->subMonthNoOverflow()->startOfMonth(),
                $now->copy()->subMonthNoOverflow()->endOfMonth(),
            ],
            'ytd' => [$now->copy()->startOfYear(), $now->copy()->endOfDay()],
            'all' => [Carbon::parse('1970-01-01'), $now->copy()->endOfDay()],
            default => [$now->copy()->subDays(30)->startOfDay(), $now->copy()->endOfDay()],
        };
    }
}
