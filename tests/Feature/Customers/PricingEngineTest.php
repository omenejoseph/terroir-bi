<?php

declare(strict_types=1);

namespace Tests\Feature\Customers;

use App\Enums\TenantRole;
use App\Models\Customer;
use App\Models\CustomerPrice;
use App\Models\InventoryItem;
use App\Models\PricingTier;
use App\Models\TierPrice;
use App\Services\Pricing\PricingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\InteractsWithTenancy;
use Tests\TestCase;

class PricingEngineTest extends TestCase
{
    use InteractsWithTenancy;
    use RefreshDatabase;

    private function item(int $defaultMinor = 12000): InventoryItem
    {
        return InventoryItem::create([
            'name' => 'Wine', 'sku' => 'W-'.uniqid(), 'category' => 'FINISHED',
            'unit' => 'bottles', 'default_price' => $defaultMinor,
        ]);
    }

    private function resolve(Customer $customer, InventoryItem $item): int
    {
        return app(PricingService::class)->resolve($customer, $item)->getMinorAmount();
    }

    public function test_customer_price_wins_and_ignores_rebate(): void
    {
        $this->actingAsTenant($this->createTenant());

        $tier = PricingTier::create(['name' => 'T', 'rebate_percent' => 5]);
        $customer = Customer::create(['company_name' => 'C', 'email' => 'c@c.hr', 'rebate_percent' => 10, 'pricing_tier_id' => $tier->getKey()]);
        $item = $this->item(12000);
        TierPrice::create(['inventory_item_id' => $item->getKey(), 'pricing_tier_id' => $tier->getKey(), 'price' => 10000]);
        CustomerPrice::create(['inventory_item_id' => $item->getKey(), 'customer_id' => $customer->getKey(), 'price' => 5000]);

        $this->assertSame(5000, $this->resolve($customer, $item));
    }

    public function test_tier_price_with_customer_rebate(): void
    {
        $this->actingAsTenant($this->createTenant());

        $tier = PricingTier::create(['name' => 'T', 'rebate_percent' => 5]);
        $customer = Customer::create(['company_name' => 'C', 'email' => 'c@c.hr', 'rebate_percent' => 10, 'pricing_tier_id' => $tier->getKey()]);
        $item = $this->item(12000);
        TierPrice::create(['inventory_item_id' => $item->getKey(), 'pricing_tier_id' => $tier->getKey(), 'price' => 10000]);

        // 10% off 100.00 = 90.00
        $this->assertSame(9000, $this->resolve($customer, $item));
    }

    public function test_tier_price_with_tier_rebate_only(): void
    {
        $this->actingAsTenant($this->createTenant());

        $tier = PricingTier::create(['name' => 'T', 'rebate_percent' => 5]);
        $customer = Customer::create(['company_name' => 'C', 'email' => 'c@c.hr', 'rebate_percent' => 0, 'pricing_tier_id' => $tier->getKey()]);
        $item = $this->item(12000);
        TierPrice::create(['inventory_item_id' => $item->getKey(), 'pricing_tier_id' => $tier->getKey(), 'price' => 10000]);

        // 5% off 100.00 = 95.00
        $this->assertSame(9500, $this->resolve($customer, $item));
    }

    public function test_default_price_with_tier_rebate_when_no_tier_price(): void
    {
        $this->actingAsTenant($this->createTenant());

        $tier = PricingTier::create(['name' => 'T', 'rebate_percent' => 5]);
        $customer = Customer::create(['company_name' => 'C', 'email' => 'c@c.hr', 'rebate_percent' => 0, 'pricing_tier_id' => $tier->getKey()]);
        $item = $this->item(12000);

        // 5% off default 120.00 = 114.00
        $this->assertSame(11400, $this->resolve($customer, $item));
    }

    public function test_default_price_with_customer_rebate_no_tier(): void
    {
        $this->actingAsTenant($this->createTenant());

        $customer = Customer::create(['company_name' => 'C', 'email' => 'c@c.hr', 'rebate_percent' => 10]);
        $item = $this->item(12000);

        // 10% off default 120.00 = 108.00
        $this->assertSame(10800, $this->resolve($customer, $item));
    }

    public function test_nothing_priced_returns_zero(): void
    {
        $this->actingAsTenant($this->createTenant());

        $customer = Customer::create(['company_name' => 'C', 'email' => 'c@c.hr']);
        $item = $this->item()->forceFill(['default_price' => null]);
        $item->save();

        $this->assertSame(0, $this->resolve($customer, $item));
    }

    public function test_resolved_prices_endpoint(): void
    {
        $tenant = $this->createTenant();
        $admin = $this->createMember($tenant, [TenantRole::Admin]);

        $this->actingAsTenant($tenant);
        $customer = Customer::create(['company_name' => 'C', 'email' => 'c@c.hr', 'rebate_percent' => 10]);
        $item = $this->item(12000);
        $this->forgetTenant();

        Sanctum::actingAs($admin);
        $this->getJson("/api/v1/customers/{$customer->getKey()}/resolved-prices?item_ids={$item->getKey()}", $this->tenantHeader($tenant))
            ->assertOk()
            ->assertJsonPath("data.{$item->getKey()}.minor", 10800);
    }
}
