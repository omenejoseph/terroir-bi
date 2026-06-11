<?php

declare(strict_types=1);

namespace Tests\Feature\Customers;

use App\Enums\TenantRole;
use App\Models\Customer;
use App\Models\InventoryItem;
use App\Models\PricingTier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\InteractsWithTenancy;
use Tests\TestCase;

class ItemPriceBookTest extends TestCase
{
    use InteractsWithTenancy;
    use RefreshDatabase;

    public function test_lists_tier_and_customer_prices_for_an_item(): void
    {
        $tenant = $this->createTenant();
        $admin = $this->createMember($tenant, [TenantRole::Admin]);

        $this->actingAsTenant($tenant);
        $item = InventoryItem::create([
            'name' => 'Plavac', 'sku' => 'PLV', 'category' => 'FINISHED',
            'unit' => 'bottles', 'default_price' => 2999,
        ]);
        $tier = PricingTier::create(['name' => 'Wholesale', 'rebate_percent' => 15]);
        $customer = Customer::create(['company_name' => 'Konoba Riva', 'email' => 'r@example.com']);
        $this->forgetTenant();

        Sanctum::actingAs($admin);

        // Seed a tier price and a customer price via the existing upsert endpoints.
        $this->putJson("/api/v1/inventory-items/{$item->getKey()}/tier-price/{$tier->getKey()}", ['price' => 1999], $this->tenantHeader($tenant))->assertOk();
        $this->putJson("/api/v1/inventory-items/{$item->getKey()}/customer-price/{$customer->getKey()}", ['price' => 1500], $this->tenantHeader($tenant))->assertOk();

        $this->getJson("/api/v1/inventory-items/{$item->getKey()}/tier-prices", $this->tenantHeader($tenant))
            ->assertOk()
            ->assertJsonPath('data.0.pricing_tier_id', $tier->getKey())
            ->assertJsonPath('data.0.tier_name', 'Wholesale')
            ->assertJsonPath('data.0.price.minor', 1999);

        $this->getJson("/api/v1/inventory-items/{$item->getKey()}/customer-prices", $this->tenantHeader($tenant))
            ->assertOk()
            ->assertJsonPath('data.0.customer_id', $customer->getKey())
            ->assertJsonPath('data.0.company_name', 'Konoba Riva')
            ->assertJsonPath('data.0.price.minor', 1500);
    }

    public function test_price_book_lists_require_pricing_visibility(): void
    {
        $tenant = $this->createTenant();
        $member = $this->createMember($tenant, [TenantRole::WineClub]);
        $this->actingAsTenant($tenant);
        $item = InventoryItem::create(['name' => 'P', 'sku' => 'P', 'category' => 'FINISHED', 'unit' => 'bottles']);
        $this->forgetTenant();

        Sanctum::actingAs($member);
        $this->getJson("/api/v1/inventory-items/{$item->getKey()}/tier-prices", $this->tenantHeader($tenant))->assertForbidden();
        $this->getJson("/api/v1/inventory-items/{$item->getKey()}/customer-prices", $this->tenantHeader($tenant))->assertForbidden();
    }
}
