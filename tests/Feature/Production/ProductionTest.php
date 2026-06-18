<?php

declare(strict_types=1);

namespace Tests\Feature\Production;

use App\Enums\TenantRole;
use App\Models\InventoryItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\InteractsWithTenancy;
use Tests\TestCase;

class ProductionTest extends TestCase
{
    use InteractsWithTenancy;
    use RefreshDatabase;

    private function wine(): InventoryItem
    {
        return InventoryItem::create([
            'name' => 'Plavac', 'sku' => 'PLV', 'category' => 'FINISHED', 'unit' => 'bottles',
            'sales_unit' => 'bottles', 'bottles_per_case' => 6, 'default_price' => 1500, 'cost_per_unit' => 600,
        ]);
    }

    public function test_plan_rows_calculate_revenue_cost_and_margin(): void
    {
        $tenant = $this->createTenant();
        $admin = $this->createMember($tenant, [TenantRole::Admin]);
        $this->actingAsTenant($tenant);
        $item = $this->wine();
        $this->forgetTenant();

        Sanctum::actingAs($admin);

        $planId = $this->postJson('/api/v1/production-plans', ['name' => 'Spring run'], $this->tenantHeader($tenant))
            ->assertCreated()->json('data.id');

        $this->patchJson("/api/v1/production-plans/{$planId}", [
            'rows' => [['base_item_id' => $item->getKey(), 'quantity' => 100, 'plan_unit' => 'bottles']],
        ], $this->tenantHeader($tenant))->assertOk()->assertJsonPath('data.rows.0.quantity', '100.000');

        // 100 bottles × €15 = €1500 revenue; × €6 = €600 cost; margin 60%.
        $this->getJson("/api/v1/production-plans/{$planId}/calculate", $this->tenantHeader($tenant))
            ->assertOk()
            ->assertJsonPath('data.totals.revenue', 150000)
            ->assertJsonPath('data.totals.cost', 60000)
            ->assertJsonPath('data.totals.margin_pct', 60);
    }

    public function test_confirm_auto_creates_a_new_vintage_item(): void
    {
        $tenant = $this->createTenant();
        $admin = $this->createMember($tenant, [TenantRole::Admin]);
        $this->actingAsTenant($tenant);
        $item = $this->wine();
        $this->forgetTenant();

        Sanctum::actingAs($admin);

        $planId = $this->postJson('/api/v1/production-plans', ['name' => 'Vintage roll'], $this->tenantHeader($tenant))->json('data.id');
        $this->patchJson("/api/v1/production-plans/{$planId}", [
            'rows' => [['base_item_id' => $item->getKey(), 'quantity' => 50, 'plan_unit' => 'cases', 'new_vintage' => '2027']],
        ], $this->tenantHeader($tenant))->assertOk();

        $this->postJson("/api/v1/production-plans/{$planId}/confirm", [], $this->tenantHeader($tenant))
            ->assertOk()
            ->assertJsonPath('data.status', 'CONFIRMED');

        $this->assertDatabaseHas('inventory_items', [
            'name' => 'Plavac', 'vintage' => '2027', 'is_auto_created' => true, 'base_product_id' => $item->getKey(),
        ]);
    }
}
