<?php

declare(strict_types=1);

namespace App\Actions\Hospitality;

use App\Models\HospitalityAgencyPrice;

class RemoveAgencyPriceAction
{
    public function execute(HospitalityAgencyPrice $price): void
    {
        $price->delete();
    }
}
