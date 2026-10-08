<?php

declare(strict_types=1);

namespace App\Services\LegacyImport\Steps;

use App\Enums\CustomerType;
use App\Services\LegacyImport\ImportContext;
use App\Services\LegacyImport\Support\Normalize;
use Illuminate\Support\Str;

/** Customer (+ tier/customer prices, portal visibility overrides). */
class CustomersStep extends AbstractStep
{
    public function name(): string
    {
        return 'customers';
    }

    public function dependsOn(): array
    {
        return ['pricing_tiers', 'inventory'];
    }

    public function run(ImportContext $ctx): void
    {
        $this->customers($ctx);
        $this->tierPrices($ctx);
        $this->customerPrices($ctx);
        $this->overrides($ctx);
    }

    /** Same rules as the 2026_06_14 customer_type backfill; the agency flag wins. */
    public static function type(?string $legacy, bool $isAgency): CustomerType
    {
        $t = strtolower(trim((string) $legacy));

        return match (true) {
            $isAgency => CustomerType::Agency,
            str_contains($t, 'shipshop'), str_contains($t, 'consign') => CustomerType::Shipshop,
            str_contains($t, 'shop'), str_contains($t, 'retail'), str_contains($t, 'vinotek') => CustomerType::Retail,
            $t === 'other' => CustomerType::Other,
            default => CustomerType::Wholesale,
        };
    }

    private function customers(ImportContext $ctx): void
    {
        $used = [];
        $defaulted = 0;

        foreach ($this->rows($ctx, 'Customer') as $r) {
            $ctx->report->read('customers');
            $isAgency = Normalize::bool($r->isAgency);

            if (Normalize::str($r->customerType) === null) {
                $defaulted++;
            }

            $email = Str::lower((string) Normalize::str($r->email));
            if ($email === '' || isset($used[$email])) {
                $why = $email === '' ? 'blank email' : "duplicate email {$email}";
                $email = "legacy+{$r->id}@import.invalid";
                $ctx->report->warn('customers', "{$r->companyName}: {$why}; synthesized {$email}");
            }
            $used[$email] = true;

            $tier = $r->pricingTierId === null ? null : $ctx->ids->get('PricingTier', (string) $r->pricingTierId);

            $this->put($ctx, 'Customer', (string) $r->id, 'customers', [
                'company_name' => trim((string) $r->companyName),
                'contact_name' => Normalize::str($r->contactName),
                'email' => $email,
                'phone' => Normalize::str($r->phone),
                'address' => Normalize::str($r->address),
                'city' => Normalize::str($r->city),
                'state' => Normalize::str($r->state),
                'zip' => Normalize::str($r->zip),
                'country' => Normalize::str($r->country),
                'notes' => Normalize::str($r->notes),
                'is_active' => Normalize::bool($r->isActive),
                'rebate_percent' => Normalize::dec($r->rebatePercent, 2),
                'exclude_from_stats' => Normalize::bool($r->excludeFromStats),
                'hide_prices' => Normalize::bool($r->hidePrices),
                // Preserved so live portal links keep working.
                'order_token' => Normalize::str($r->orderToken),
                'pricing_tier_id' => $tier,
                'customer_type' => self::type($r->customerType, $isAgency)->value,
                'oib' => Normalize::str($r->oib),
                'is_agency' => $isAgency,
                'allow_single_bottle' => Normalize::bool($r->allowSingleBottle),
                'reorder_contacted_at' => Normalize::date($r->reorderContactedAt),
                'created_at' => Normalize::date($r->createdAt),
                'updated_at' => Normalize::date($r->updatedAt),
            ]);
            $ctx->report->written('customers');
        }

        if ($defaulted > 0) {
            $ctx->report->warn('customers', "{$defaulted} customers had no customerType; defaulted via rules (WHOLESALE unless agency)");
        }
    }

    private function tierPrices(ImportContext $ctx): void
    {
        foreach ($this->rows($ctx, 'TierPrice') as $r) {
            $ctx->report->read('tier_prices');
            $item = $ctx->ids->get('InventoryItem', (string) $r->inventoryItemId);
            $tier = $ctx->ids->get('PricingTier', (string) $r->pricingTierId);
            if ($item === null || $tier === null) {
                $ctx->report->skipped('tier_prices', "tier price {$r->id}: item/tier not migrated");

                continue;
            }
            $this->put($ctx, 'TierPrice', (string) $r->id, 'tier_prices', [
                'inventory_item_id' => $item,
                'pricing_tier_id' => $tier,
                'price' => Normalize::minor($r->price),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $ctx->report->written('tier_prices');
        }
    }

    private function customerPrices(ImportContext $ctx): void
    {
        foreach ($this->rows($ctx, 'CustomerPrice') as $r) {
            $ctx->report->read('customer_prices');
            $item = $ctx->ids->get('InventoryItem', (string) $r->inventoryItemId);
            $customer = $ctx->ids->get('Customer', (string) $r->customerId);
            if ($item === null || $customer === null) {
                $ctx->report->skipped('customer_prices', "customer price {$r->id}: item/customer not migrated");

                continue;
            }
            $this->put($ctx, 'CustomerPrice', (string) $r->id, 'customer_prices', [
                'inventory_item_id' => $item,
                'customer_id' => $customer,
                'price' => Normalize::minor($r->price),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $ctx->report->written('customer_prices');
        }
    }

    private function overrides(ImportContext $ctx): void
    {
        foreach ($this->rows($ctx, 'CustomerProductOverride') as $r) {
            $ctx->report->read('customer_product_overrides');
            $item = $ctx->ids->get('InventoryItem', (string) $r->inventoryItemId);
            $customer = $ctx->ids->get('Customer', (string) $r->customerId);
            if ($item === null || $customer === null) {
                $ctx->report->skipped('customer_product_overrides', "override {$r->id}: item/customer not migrated");

                continue;
            }
            $this->put($ctx, 'CustomerProductOverride', (string) $r->id, 'customer_product_overrides', [
                'inventory_item_id' => $item,
                'customer_id' => $customer,
                'visible' => Normalize::bool($r->visible),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $ctx->report->written('customer_product_overrides');
        }
    }
}
