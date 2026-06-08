<?php

declare(strict_types=1);

namespace App\Services\Pricing;

use App\Models\Customer;
use App\Models\CustomerPrice;
use App\Models\InventoryItem;
use App\Models\TierPrice;
use App\Support\Money\CurrencyRegistry;
use App\Support\Money\Money;

/**
 * Price resolution (docs/05-pricing-engine.md). Precedence, highest wins:
 *
 *   1. CustomerPrice  → returned as-is (NO rebate)
 *   2. TierPrice      → rebate applied
 *   3. default_price  → rebate applied
 *   4. otherwise      → zero
 *
 * Rebate is not additive: a customer-level rebate overrides the tier's.
 * All math is in integer minor units.
 */
class PricingService
{
    public function resolve(Customer $customer, InventoryItem $item): Money
    {
        // 1. Customer-specific absolute price — no rebate.
        $customerPrice = CustomerPrice::query()
            ->where('customer_id', $customer->getKey())
            ->where('inventory_item_id', $item->getKey())
            ->first();

        if ($customerPrice !== null) {
            return $customerPrice->price;
        }

        // 2. Tier price, else 3. default price.
        $base = null;
        if ($customer->pricing_tier_id !== null) {
            $base = TierPrice::query()
                ->where('pricing_tier_id', $customer->pricing_tier_id)
                ->where('inventory_item_id', $item->getKey())
                ->first()?->price;
        }

        $base ??= $item->default_price;

        if ($base === null) {
            return Money::zero($this->currencyFor($item));
        }

        // Rebate: customer overrides tier.
        $rebatePercent = (float) $customer->effectiveRebatePercent();

        if ($rebatePercent <= 0) {
            return $base;
        }

        // final = round(base * (1 - rebate/100)); rebate has 2 decimals.
        $rebateBasisPoints = (int) round($rebatePercent * 100);
        $finalMinor = (int) round($base->getMinorAmount() * (10000 - $rebateBasisPoints) / 10000);

        return Money::fromMinor($finalMinor, $base->getCurrencyCode());
    }

    /**
     * Resolve prices for many items at once.
     *
     * @param  iterable<InventoryItem>  $items
     * @return array<string, Money> keyed by inventory item id
     */
    public function resolveForCustomer(Customer $customer, iterable $items): array
    {
        $prices = [];
        foreach ($items as $item) {
            $prices[$item->getKey()] = $this->resolve($customer, $item);
        }

        return $prices;
    }

    private function currencyFor(InventoryItem $item): string
    {
        if ($item->default_price !== null) {
            return $item->default_price->getCurrencyCode();
        }

        $tenant = $item->tenant;
        $currency = $tenant?->settings()->first()?->default_currency;

        return $currency ?? CurrencyRegistry::default()->code;
    }
}
