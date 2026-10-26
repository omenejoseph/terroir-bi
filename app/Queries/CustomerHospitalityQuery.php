<?php

declare(strict_types=1);

namespace App\Queries;

use App\Enums\ReservationStatus;
use App\Models\Customer;
use App\Models\HospitalityAgencyPrice;
use App\Models\HospitalityProgram;
use App\Models\Reservation;
use App\Services\Hospitality\ReservationPresenter;
use App\Support\Money\CurrencyRegistry;
use App\Support\Money\Money;
use App\Tenancy\Contracts\TenantContext;

/**
 * The customer page's Hospitality tab (the old app's agency Pricing table and the customer's
 * Reservations tab): every program with what this customer pays for it, and their bookings.
 */
class CustomerHospitalityQuery
{
    /** The tab lists the most recent bookings; the full history lives on the reservation list. */
    private const RESERVATION_LIMIT = 50;

    public function __construct(
        private readonly ReservationPresenter $presenter,
        private readonly TenantContext $tenant,
    ) {}

    /**
     * @return array{prices: list<array<string, mixed>>, reservations: list<array<string, mixed>>, reservation_count: int, revenue: array<string, mixed>}
     */
    public function get(Customer $customer): array
    {
        $book = HospitalityAgencyPrice::query()
            ->where('customer_id', $customer->getKey())
            ->get()
            ->keyBy('program_id');

        // Every program, so an agency's missing prices show as "pays base" and can be added in place.
        $prices = [];
        foreach (HospitalityProgram::query()->orderByDesc('is_active')->orderBy('sort_order')->orderBy('name')->get() as $program) {
            $price = $book->get($program->getKey());
            $base = $program->base_price->getMinorAmount();

            if (! $price instanceof HospitalityAgencyPrice && ! $program->is_active) {
                continue;
            }

            $prices[] = [
                'program_id' => (string) $program->getKey(),
                'program_name' => $program->name,
                'is_active' => $program->is_active,
                'base_price' => $program->base_price->jsonSerialize(),
                'price_per_pax' => $price?->price_per_pax->jsonSerialize(),
                'rebate_percent' => $price?->rebate_percent !== null ? (float) $price->rebate_percent : null,
                'effective_rebate_percent' => $price instanceof HospitalityAgencyPrice && $base > 0
                    ? round((1 - $price->price_per_pax->getMinorAmount() / $base) * 100, 1)
                    : null,
            ];
        }

        $reservations = Reservation::query()
            ->where('customer_id', $customer->getKey())
            ->with(['program', 'customer', 'createdBy'])
            ->orderByDesc('date')
            ->limit(self::RESERVATION_LIMIT)
            ->get();

        // Cancelled bookings never earned anything, as on the tiles and in analytics.
        $revenue = (int) Reservation::query()
            ->where('customer_id', $customer->getKey())
            ->where('status', '!=', ReservationStatus::Cancelled)
            ->sum('total_amount');
        $currency = $this->tenant->current()?->settings()->first()?->default_currency ?? CurrencyRegistry::default()->code;

        return [
            'prices' => $prices,
            'reservations' => array_values($reservations->map(fn (Reservation $r): array => $this->presenter->row($r))->all()),
            'reservation_count' => Reservation::query()->where('customer_id', $customer->getKey())->count(),
            'revenue' => Money::fromMinor($revenue, $currency)->jsonSerialize(),
        ];
    }
}
