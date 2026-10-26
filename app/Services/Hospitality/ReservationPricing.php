<?php

declare(strict_types=1);

namespace App\Services\Hospitality;

use App\Models\Customer;
use App\Models\HospitalityAgencyPrice;
use App\Models\HospitalityProgram;

/**
 * The one place a reservation's per-head price is decided. All amounts are integer minor units.
 *
 * For a given program and customer:
 *  1. an agency price in the price book wins outright;
 *  2. otherwise a customer who is NOT an agency gets their standing rebate off the base price;
 *  3. otherwise the base price.
 *
 * Agencies never get the customer-level rebate: the old app once applied it to them by mistake and
 * had to repair the stored totals (scripts/fix-agency-reservation-prices.ts). Their discount lives
 * only in the price book.
 */
class ReservationPricing
{
    public function pricePerPax(HospitalityProgram $program, ?Customer $customer): int
    {
        $base = $program->base_price->getMinorAmount();

        if ($customer === null) {
            return $base;
        }

        $agencyPrice = HospitalityAgencyPrice::query()
            ->where('program_id', $program->getKey())
            ->where('customer_id', $customer->getKey())
            ->first();

        if ($agencyPrice !== null) {
            return $agencyPrice->price_per_pax->getMinorAmount();
        }

        if (! $customer->is_agency && (float) $customer->rebate_percent > 0) {
            return $this->afterRebate($base, $customer->rebate_percent);
        }

        return $base;
    }

    public function total(int $pricePerPax, int $pax): int
    {
        return $pricePerPax * $pax;
    }

    /**
     * `base × (1 − rebate/100)` in whole minor units, half rounded up. Works in basis points so a
     * 12.5 % rebate is exact; the old app rounded the same way to two decimals.
     */
    public function afterRebate(int $baseMinor, string|float|int $rebatePercent): int
    {
        $basisPoints = (int) round(max(0.0, min(100.0, (float) $rebatePercent)) * 100);

        return intdiv($baseMinor * (10_000 - $basisPoints) + 5_000, 10_000);
    }
}
