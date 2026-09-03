<?php

declare(strict_types=1);

namespace App\Services\Customers;

use App\Models\PricingTier;

/**
 * The pricing-tier picker: the Customers list's Tier filter and the Customer
 * — Create/Edit drawer's tier select both need the same small option list, so
 * it lives here rather than being written twice.
 */
class PricingTierOptions
{
    /** @return list<array{id: string, name: string, rebate_percent: string}> */
    public function list(): array
    {
        return PricingTier::query()
            ->orderBy('name')
            ->get(['id', 'name', 'rebate_percent'])
            ->map(fn (PricingTier $tier): array => [
                'id' => $tier->getKey(),
                'name' => $tier->name,
                'rebate_percent' => (string) $tier->rebate_percent,
            ])
            ->all();
    }
}
