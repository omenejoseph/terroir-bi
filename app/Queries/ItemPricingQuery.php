<?php

declare(strict_types=1);

namespace App\Queries;

use App\Models\CustomerPrice;
use App\Models\InventoryItem;
use App\Models\TierPrice;

/**
 * An item's price book (Product Detail · Pricing tab, Figma 449:1577): every
 * pricing tier's absolute price for it, and every customer's own negotiated
 * override — the inverse of Web\CustomerPriceController's per-customer list.
 *
 * Api\PriceController::itemTierPrices()/itemCustomerPrices() read the same
 * rows, through this same class, so the JSON API and this Inertia page cannot
 * disagree about an item's price book.
 */
class ItemPricingQuery
{
    /**
     * @return list<array<string, mixed>>
     */
    public function tierPrices(InventoryItem $item): array
    {
        return array_values(TierPrice::query()
            ->where('inventory_item_id', $item->getKey())
            ->with('pricingTier')
            ->get()
            ->map(fn (TierPrice $tierPrice): array => [
                'pricing_tier_id' => $tierPrice->pricing_tier_id,
                'tier_name' => $tierPrice->pricingTier?->name,
                'rebate_percent' => $tierPrice->pricingTier?->rebate_percent,
                'price' => $tierPrice->price->jsonSerialize(),
            ])
            ->all());
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function customerOverrides(InventoryItem $item): array
    {
        return array_values(CustomerPrice::query()
            ->where('inventory_item_id', $item->getKey())
            ->with('customer')
            ->get()
            ->map(fn (CustomerPrice $override): array => [
                'customer_id' => $override->customer_id,
                'company_name' => $override->customer?->company_name,
                'price' => $override->price->jsonSerialize(),
            ])
            ->all());
    }
}
