<?php

declare(strict_types=1);

namespace App\Services\Hospitality;

use App\Models\Customer;
use App\Models\HospitalityAgencyPrice;
use App\Models\HospitalityProgram;

/** The lists the hospitality pages pick from: programs, agencies and a program's price book. */
class HospitalityOptions
{
    /**
     * Every program, active first in the order they were set up. Retired ones stay listed so the
     * filters and old bookings still name them; the reservation form offers only active ones.
     *
     * @return list<array<string, mixed>>
     */
    public function programs(): array
    {
        return array_values(HospitalityProgram::query()
            ->withCount(['agencyPrices', 'reservations'])
            ->orderByDesc('is_active')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get()
            ->map(fn (HospitalityProgram $program): array => [
                'id' => $program->getKey(),
                'name' => $program->name,
                'description' => $program->description,
                'duration_mins' => $program->duration_mins,
                'base_price' => $program->base_price->jsonSerialize(),
                'max_pax' => $program->max_pax,
                'is_active' => $program->is_active,
                'agency_price_count' => (int) $program->getAttribute('agency_prices_count'),
                'reservation_count' => (int) $program->getAttribute('reservations_count'),
            ])
            ->all());
    }

    /**
     * Customers marked as agencies. The old app's pickers only ever offered agencies (the create
     * form only active ones); `is_active` is carried so the form can do the same.
     *
     * @return list<array{id: string, name: string, is_active: bool}>
     */
    public function agencies(): array
    {
        return array_values(Customer::query()
            ->where('is_agency', true)
            ->orderBy('company_name')
            ->get(['id', 'company_name', 'is_active'])
            ->map(fn (Customer $customer): array => [
                'id' => (string) $customer->getKey(),
                'name' => $customer->company_name,
                'is_active' => (bool) $customer->is_active,
            ])
            ->all());
    }

    /**
     * One program's price book: each agency with a price, how far below base it is, and whether
     * it was set as a rebate (and so follows the base price).
     *
     * @return list<array<string, mixed>>
     */
    public function agencyPrices(string $programId): array
    {
        $program = HospitalityProgram::query()->find($programId);

        if (! $program instanceof HospitalityProgram) {
            return [];
        }

        $base = $program->base_price->getMinorAmount();

        return array_values(HospitalityAgencyPrice::query()
            ->where('program_id', $program->getKey())
            ->with('customer:id,company_name')
            ->get()
            ->sortBy(fn (HospitalityAgencyPrice $price): string => (string) $price->customer?->company_name)
            ->map(fn (HospitalityAgencyPrice $price): array => [
                'id' => $price->getKey(),
                'customer_id' => $price->customer_id,
                'customer_name' => $price->customer?->company_name,
                'price_per_pax' => $price->price_per_pax->jsonSerialize(),
                'rebate_percent' => $price->rebate_percent !== null ? (float) $price->rebate_percent : null,
                // What the price works out to off base, for a price entered as a flat figure.
                'effective_rebate_percent' => $base > 0
                    ? round((1 - $price->price_per_pax->getMinorAmount() / $base) * 100, 1)
                    : null,
            ])
            ->all());
    }
}
