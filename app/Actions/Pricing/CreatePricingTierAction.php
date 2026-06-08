<?php

declare(strict_types=1);

namespace App\Actions\Pricing;

use App\DataTransferObjects\PricingTierData;
use App\Models\PricingTier;

class CreatePricingTierAction
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function execute(array $attributes): PricingTierData
    {
        return PricingTierData::fromModel(PricingTier::create($attributes));
    }
}
