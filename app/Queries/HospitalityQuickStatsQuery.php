<?php

declare(strict_types=1);

namespace App\Queries;

use App\Enums\ReservationStatus;
use App\Models\Reservation;
use App\Support\Money\CurrencyRegistry;
use App\Support\Money\Money;
use App\Tenancy\Contracts\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * The tiles above the reservation list: today, month to date and year to date, each against the
 * same window a year earlier, plus the average group size and how much of this month is confirmed.
 *
 * Counted the way the analytics count: cancelled reservations and customers marked "exclude from
 * stats" are left out. The old app's tiles counted every status and ignored that flag, which made
 * them disagree with its own analytics tab (docs/21 §2.3); they are aligned here on purpose.
 */
class HospitalityQuickStatsQuery
{
    public function __construct(private readonly TenantContext $tenant) {}

    /**
     * @return array{today: array<string, mixed>, month: array<string, mixed>, year: array<string, mixed>, avg_pax: float, confirmed: int, booked: int}
     */
    public function get(?Carbon $now = null): array
    {
        $now ??= Carbon::now();
        $currency = $this->currency();

        $windows = [
            'today' => [$now->copy()->startOfDay(), $now->copy()->endOfDay()],
            'month' => [$now->copy()->startOfMonth(), $now->copy()->endOfDay()],
            'year' => [$now->copy()->startOfYear(), $now->copy()->endOfDay()],
        ];

        $tiles = [];
        foreach ($windows as $key => [$from, $to]) {
            $current = $this->totals($from, $to);
            $previous = $this->totals($from->copy()->subYearNoOverflow(), $to->copy()->subYearNoOverflow());

            $tiles[$key] = [
                'count' => $current['count'],
                'pax' => $current['pax'],
                'revenue' => Money::fromMinor($current['revenue'], $currency)->jsonSerialize(),
                'previous_revenue' => Money::fromMinor($previous['revenue'], $currency)->jsonSerialize(),
                'delta_percent' => $previous['revenue'] > 0
                    ? round(($current['revenue'] - $previous['revenue']) / $previous['revenue'] * 100, 1)
                    : null,
            ];
        }

        // Group size and the confirmed share are about the whole current month, booked ahead included.
        $month = $this->counted($now->copy()->startOfMonth(), $now->copy()->endOfMonth());
        $booked = (clone $month)->count();
        $converted = array_filter(ReservationStatus::cases(), fn (ReservationStatus $s): bool => $s->isConverted());
        $confirmed = (clone $month)->whereIn('status', array_map(fn (ReservationStatus $s): string => $s->value, $converted))->count();

        $year = $this->totals($now->copy()->startOfYear(), $now->copy()->endOfYear());

        return [
            ...$tiles,
            'avg_pax' => $year['count'] > 0 ? round($year['pax'] / $year['count'], 1) : 0.0,
            'confirmed' => $confirmed,
            'booked' => $booked,
        ];
    }

    /**
     * @return array{count: int, pax: int, revenue: int}
     */
    private function totals(Carbon $from, Carbon $to): array
    {
        $row = $this->counted($from, $to)
            ->toBase()
            ->selectRaw('COUNT(*) as reservations, COALESCE(SUM(pax), 0) as pax, COALESCE(SUM(total_amount), 0) as revenue')
            ->first();

        return [
            'count' => (int) ($row->reservations ?? 0),
            'pax' => (int) ($row->pax ?? 0),
            'revenue' => (int) ($row->revenue ?? 0),
        ];
    }

    /**
     * Reservations in the window that count towards the figures.
     *
     * @return Builder<Reservation>
     */
    private function counted(Carbon $from, Carbon $to): Builder
    {
        return Reservation::query()
            ->whereBetween('date', [$from, $to])
            ->where('status', '!=', ReservationStatus::Cancelled)
            ->where(fn (Builder $q) => $q
                ->whereNull('customer_id')
                ->orWhereHas('customer', fn (Builder $c) => $c->where('exclude_from_stats', false)));
    }

    private function currency(): string
    {
        $currency = $this->tenant->current()?->settings()->first()?->default_currency;

        return $currency ?? CurrencyRegistry::default()->code;
    }
}
