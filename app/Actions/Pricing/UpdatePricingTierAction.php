<?php

declare(strict_types=1);

namespace App\Actions\Pricing;

use App\DataTransferObjects\PricingTierData;
use App\Models\PricingTier;

class UpdatePricingTierAction
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function execute(PricingTier $tier, array $attributes): PricingTierData
    {
        $tier->fill($attributes)->save();

        return PricingTierData::fromModel($tier);
    }
}
