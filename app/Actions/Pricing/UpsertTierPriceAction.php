<?php

declare(strict_types=1);

namespace App\Actions\Pricing;

use App\Models\InventoryItem;
use App\Models\PricingTier;
use App\Models\TierPrice;

class UpsertTierPriceAction
{
    public function execute(InventoryItem $item, PricingTier $tier, int $priceMinor): TierPrice
    {
        return TierPrice::updateOrCreate(
            ['inventory_item_id' => $item->getKey(), 'pricing_tier_id' => $tier->getKey()],
            ['price' => $priceMinor],
        );
    }
}
