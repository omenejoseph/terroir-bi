<?php

declare(strict_types=1);

namespace App\Services\Pricing;

use App\Enums\InventoryCategory;
use App\Models\Customer;
use App\Models\InventoryItem;
use App\Models\PricingTier;
use Illuminate\Database\Eloquent\Collection;

/**
 * The Pricing tiers page's "what customers on each tier will pay" table: a
 * handful of priced, for-sale wines with the list price and the price each
 * tier resolves to.
 *
 * Each price comes from PricingService itself, for an unsaved customer placed
 * on the tier with no rebate of its own — so the preview can never disagree
 * with what an order for a real customer on that tier would charge.
 */
class TierPricePreview
{
    public function __construct(private readonly PricingService $pricing) {}

    /**
     * @param  Collection<int, PricingTier>  $tiers
     * @return list<array{id: string, name: string, vintage: string|null, list_price: int, currency: string, prices: array<string, int>}>
     */
    public function rows(Collection $tiers, int $limit = 6): array
    {
        if ($tiers->isEmpty()) {
            return [];
        }

        $items = InventoryItem::query()
            ->where('category', InventoryCategory::Finished->value)
            ->where('is_active', true)
            ->where('is_for_sale', true)
            ->where('default_price', '>', 0)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->limit($limit)
            ->get();

        $customers = $tiers->mapWithKeys(function (PricingTier $tier): array {
            $customer = new Customer(['pricing_tier_id' => $tier->getKey(), 'rebate_percent' => '0']);
            $customer->setRelation('pricingTier', $tier);

            return [(string) $tier->getKey() => $customer];
        });

        return array_values($items->map(function (InventoryItem $item) use ($customers): array {
            $list = $item->default_price;

            return [
                'id' => (string) $item->getKey(),
                'name' => $item->name,
                'vintage' => $item->vintage,
                'list_price' => $list?->getMinorAmount() ?? 0,
                'currency' => $list?->getCurrencyCode() ?? 'EUR',
                'prices' => $customers
                    ->map(fn (Customer $customer): int => $this->pricing->resolve($customer, $item)->getMinorAmount())
                    ->all(),
            ];
        })->all());
    }
}
