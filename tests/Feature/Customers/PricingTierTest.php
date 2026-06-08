<?php

declare(strict_types=1);

namespace Tests\Feature\Customers;

use App\Enums\TenantRole;
use App\Models\Customer;
use App\Models\PricingTier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\InteractsWithTenancy;
use Tests\TestCase;

class PricingTierTest extends TestCase
{
    use InteractsWithTenancy;
    use RefreshDatabase;

    public function test_create_and_list_tiers_with_customer_count(): void
    {
        $tenant = $this->createTenant();
        $admin = $this->createMember($tenant, [TenantRole::Admin]);
        Sanctum::actingAs($admin);

        $tierId = $this->postJson('/api/v1/pricing-tiers', [
            'name' => 'Wholesale',
            'rebate_percent' => 15,
        ], $this->tenantHeader($tenant))->assertCreated()->json('data.id');

        $this->actingAsTenant($tenant);
        Customer::create(['company_name' => 'A', 'email' => 'a@a.hr', 'pricing_tier_id' => $tierId]);
        $this->forgetTenant();

        $this->getJson('/api/v1/pricing-tiers', $this->tenantHeader($tenant))
            ->assertOk()
            ->assertJsonPath('data.0.name', 'Wholesale')
            ->assertJsonPath('data.0.customers_count', 1);
    }

    public function test_tier_name_is_unique_per_tenant(): void
    {
        $tenant = $this->createTenant();
        Sanctum::actingAs($this->createMember($tenant, [TenantRole::Admin]));

        $this->postJson('/api/v1/pricing-tiers', ['name' => 'Retail'], $this->tenantHeader($tenant))->assertCreated();
        $this->postJson('/api/v1/pricing-tiers', ['name' => 'Retail'], $this->tenantHeader($tenant))
            ->assertStatus(422)->assertJsonValidationErrorFor('name');
    }

    public function test_tier_can_be_updated_and_deleted(): void
    {
        $tenant = $this->createTenant();
        $admin = $this->createMember($tenant, [TenantRole::Admin]);
        $this->actingAsTenant($tenant);
        $tier = PricingTier::create(['name' => 'Old', 'rebate_percent' => 5]);
        $this->forgetTenant();

        Sanctum::actingAs($admin);
        $this->patchJson("/api/v1/pricing-tiers/{$tier->getKey()}", ['name' => 'New'], $this->tenantHeader($tenant))
            ->assertOk()->assertJsonPath('data.name', 'New');

        $this->deleteJson("/api/v1/pricing-tiers/{$tier->getKey()}", [], $this->tenantHeader($tenant))->assertNoContent();
    }
}
