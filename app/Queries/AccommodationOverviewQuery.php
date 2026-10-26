<?php

declare(strict_types=1);

namespace App\Queries;

use App\Enums\VillaBookingStatus;
use App\Models\Villa;
use App\Models\VillaBooking;
use App\Services\Accommodation\AccommodationPresenter;
use App\Support\Money\CurrencyRegistry;
use App\Support\Money\Money;
use App\Tenancy\Contracts\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;

/**
 * The Accommodation overview (the old app's accommodation dashboard): deposits still owed, who
 * arrives and leaves today and this week, each villa's occupancy over the next 30 days, and a
 * timeline of the bookings in that window. Cancelled bookings never count.
 */
class AccommodationOverviewQuery
{
    /** The window the occupancy figures and the timeline cover. */
    public const DAYS = 30;

    public function __construct(
        private readonly AccommodationPresenter $presenter,
        private readonly TenantContext $tenant,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function get(?Carbon $now = null): array
    {
        $today = CarbonImmutable::parse(($now ?? Carbon::now())->toDateString());
        $windowEnd = $today->addDays(self::DAYS);
        $weekEnd = $today->addDays(7);

        $live = fn () => VillaBooking::query()->with('villa')->where('status', '!=', VillaBookingStatus::Cancelled);

        // Deposits not yet paid on stays that have not started.
        $owed = (int) $live()->where('check_in', '>=', $today->toDateString())->where('deposit_paid', false)->sum('deposit_amount');

        $inWindow = $live()
            ->where('check_in', '<', $windowEnd->toDateString())
            ->where('check_out', '>', $today->toDateString())
            ->orderBy('check_in')
            ->get();

        $villas = Villa::query()->where('is_active', true)->orderBy('name')->get();

        $occupancy = $villas->map(function (Villa $villa) use ($inWindow, $today, $windowEnd): array {
            $nights = 0;
            foreach ($inWindow->where('villa_id', $villa->getKey()) as $b) {
                $start = max($b->check_in->toDateString(), $today->toDateString());
                $end = min($b->check_out->toDateString(), $windowEnd->toDateString());
                $nights += max(0, (int) CarbonImmutable::parse($start)->diffInDays(CarbonImmutable::parse($end)));
            }

            return [
                'villa_id' => (string) $villa->getKey(),
                'name' => $villa->name,
                'booked_nights' => $nights,
                'percent' => round($nights / self::DAYS * 100, 1),
            ];
        })->values()->all();

        $arrivals = fn (string $from, string $to): array => array_values($live()
            ->whereBetween('check_in', [$from, $to])->orderBy('check_in')->get()
            ->map(fn (VillaBooking $b): array => $this->presenter->booking($b))->all());
        $departures = fn (string $from, string $to): array => array_values($live()
            ->whereBetween('check_out', [$from, $to])->orderBy('check_out')->get()
            ->map(fn (VillaBooking $b): array => $this->presenter->booking($b))->all());

        $currency = $this->tenant->current()?->settings()->first()?->default_currency ?? CurrencyRegistry::default()->code;

        return [
            'today' => $today->toDateString(),
            'window_end' => $windowEnd->toDateString(),
            'deposits_owed' => Money::fromMinor($owed, $currency)->jsonSerialize(),
            'arrivals_today' => $arrivals($today->toDateString(), $today->toDateString()),
            'departures_today' => $departures($today->toDateString(), $today->toDateString()),
            'arrivals_week' => $arrivals($today->addDay()->toDateString(), $weekEnd->toDateString()),
            'departures_week' => $departures($today->addDay()->toDateString(), $weekEnd->toDateString()),
            'occupancy' => $occupancy,
            'timeline' => array_values($inWindow->map(fn (VillaBooking $b): array => $this->presenter->booking($b))->all()),
        ];
    }
}
