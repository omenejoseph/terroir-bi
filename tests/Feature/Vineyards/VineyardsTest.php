<?php

declare(strict_types=1);

namespace Tests\Feature\Vineyards;

use App\Enums\TenantRole;
use App\Models\HarvestEntry;
use App\Models\HarvestPlan;
use App\Models\Plan;
use App\Models\Supplier;
use App\Models\VineyardParcel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\InteractsWithTenancy;
use Tests\TestCase;

class VineyardsTest extends TestCase
{
    use InteractsWithTenancy;
    use RefreshDatabase;

    public function test_crop_estimate_computes_yield_from_sample(): void
    {
        $tenant = $this->createTenant();
        $admin = $this->createMember($tenant, [TenantRole::Admin]);
        $this->actingAsTenant($tenant);
        // 20 clusters × 150 g × 1000 vines / 100 sample / 1000 = 30 kg.
        $parcel = VineyardParcel::create(['name' => 'Block A', 'grape_variety' => 'Plavac', 'vine_count' => 1000]);
        $this->forgetTenant();

        Sanctum::actingAs($admin);

        $this->postJson("/api/v1/vineyard-parcels/{$parcel->getKey()}/crop-estimates", [
            'cluster_count' => 20, 'avg_cluster_weight' => 150, 'sample_vine_count' => 100,
        ], $this->tenantHeader($tenant))
            ->assertCreated()
            ->assertJsonPath('data.crop_estimates.0.estimated_yield_kg', '30.000');
    }

    public function test_grape_contract_marks_supplier_cooperant(): void
    {
        $tenant = $this->createTenant();
        $admin = $this->createMember($tenant, [TenantRole::Admin]);
        $this->actingAsTenant($tenant);
        $supplier = Supplier::create(['company_name' => 'Grower Ltd']);
        $this->forgetTenant();

        Sanctum::actingAs($admin);

        $this->postJson('/api/v1/grape-contracts', [
            'supplier_id' => $supplier->getKey(),
            'season' => '2026',
            'grape_variety' => 'Merlot',
            'estimated_kg' => 5000,
            'price_per_kg' => 150,
        ], $this->tenantHeader($tenant))->assertCreated();

        $this->assertTrue((bool) $supplier->refresh()->is_cooperant);
    }

    public function test_record_intake_mints_a_wine_lot_and_harvests_the_entry(): void
    {
        $tenant = $this->createTenant();
        $admin = $this->createMember($tenant, [TenantRole::Admin]);
        $this->actingAsTenant($tenant);
        $parcel = VineyardParcel::create(['name' => 'Block A', 'grape_variety' => 'Plavac']);
        $plan = HarvestPlan::create(['created_by_id' => $admin->getKey(), 'name' => 'Vintage 2026', 'season' => '2026', 'yield_ratio' => '0.650']);
        $entry = HarvestEntry::create(['harvest_plan_id' => $plan->getKey(), 'parcel_id' => $parcel->getKey(), 'grape_variety' => 'Plavac']);
        $this->forgetTenant();

        Sanctum::actingAs($admin);

        // 1000 kg × 0.65 = 650 L of wine.
        $this->postJson("/api/v1/harvest-plans/{$plan->getKey()}/entries/{$entry->getKey()}/intake", [
            'actual_yield_kg' => 1000,
            'grape_price_per_kg' => 150,
        ], $this->tenantHeader($tenant))
            ->assertOk()
            ->assertJsonPath('data.entries.0.status', 'HARVESTED')
            ->assertJsonPath('data.entries.0.actual_volume_liters', '650.000');

        $this->assertDatabaseHas('wine_lots', ['grape_variety' => 'Plavac', 'vintage' => '2026', 'current_volume' => '650.000']);
        $this->assertNotNull($entry->refresh()->wine_lot_id);
    }

    public function test_vineyards_module_gating(): void
    {
        $plan = Plan::create(['name' => 'No vines', 'slug' => 'no-vines-'.fake()->unique()->slug(2), 'modules' => ['orders']]);
        $tenant = $this->createTenant(['plan_id' => $plan->getKey()]);
        $admin = $this->createMember($tenant, [TenantRole::Admin]);

        Sanctum::actingAs($admin);

        $this->getJson('/api/v1/vineyard-parcels', $this->tenantHeader($tenant))
            ->assertForbidden()
            ->assertJsonPath('code', 'module_not_in_plan');
    }
}
