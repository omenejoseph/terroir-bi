<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Reads the reservation list filters off a request, and turns the period into a window.
 *
 * Reservations sit in the future as often as the past, so the presets cover whole calendar
 * spans ("This month" runs to the month's last day) rather than stopping at today the way the
 * order list's do — a booking for next Friday belongs in this month's list.
 */
final class ReservationFilters
{
    /** @var list<string> */
    public const PERIODS = ['today', 'month', 'year', 'upcoming', 'all'];

    /** The old app opened the list on the current month. */
    public const DEFAULT_PERIOD = 'month';

    /**
     * @return array{search: ?string, status: ?string, program_id: ?string, customer_id: ?string, period: string, from: ?string, to: ?string}
     */
    public static function fromRequest(Request $request): array
    {
        $period = self::str($request->query('period'));

        return [
            // The old app's search box matched the guest name only.
            'search' => self::str($request->query('search')),
            'status' => self::str($request->query('status')),
            'program_id' => self::str($request->query('program_id')),
            // 'walk_in' is the "no agency" choice in the agency filter.
            'customer_id' => self::str($request->query('customer_id')),
            'period' => in_array($period, self::PERIODS, true) ? $period : self::DEFAULT_PERIOD,
            // An explicit range beats the preset, as on the order list.
            'from' => self::date($request->query('from')),
            'to' => self::date($request->query('to')),
        ];
    }

    /**
     * The window the list shows: null bounds are open ("upcoming" has no end, "all" has neither).
     *
     * @param  array{period: string, from: ?string, to: ?string}  $filters
     * @return array{0: ?Carbon, 1: ?Carbon}
     */
    public static function window(array $filters): array
    {
        if ($filters['from'] !== null || $filters['to'] !== null) {
            return [
                $filters['from'] !== null ? Carbon::parse($filters['from'])->startOfDay() : null,
                $filters['to'] !== null ? Carbon::parse($filters['to'])->endOfDay() : null,
            ];
        }

        $now = Carbon::now();

        return match ($filters['period']) {
            'today' => [$now->copy()->startOfDay(), $now->copy()->endOfDay()],
            'year' => [$now->copy()->startOfYear(), $now->copy()->endOfYear()],
            'upcoming' => [$now->copy()->startOfDay(), null],
            'all' => [null, null],
            default => [$now->copy()->startOfMonth(), $now->copy()->endOfMonth()],
        };
    }

    private static function str(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    /** Only `YYYY-MM-DD`; anything else is dropped rather than handed to a date parser. */
    private static function date(mixed $value): ?string
    {
        $value = self::str($value);

        return $value !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1 ? $value : null;
    }
}
