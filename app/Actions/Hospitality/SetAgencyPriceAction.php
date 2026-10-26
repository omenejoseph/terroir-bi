<?php

declare(strict_types=1);

namespace App\Actions\Hospitality;

use App\Models\Customer;
use App\Models\HospitalityAgencyPrice;
use App\Models\HospitalityProgram;

class SetAgencyPriceAction
{
    /** Add or replace the agency's price for a program. */
    public function execute(HospitalityProgram $program, Customer $customer, int $pricePerPax, ?float $rebatePercent = null): HospitalityAgencyPrice
    {
        return HospitalityAgencyPrice::updateOrCreate(
            ['program_id' => $program->getKey(), 'customer_id' => $customer->getKey()],
            [
                'price_per_pax' => $pricePerPax,
                'rebate_percent' => $rebatePercent !== null && $rebatePercent > 0 ? $rebatePercent : null,
            ],
        );
    }
}
