<?php

declare(strict_types=1);

namespace Tests\Feature\Production;

use App\Enums\TenantRole;
use App\Models\InventoryItem;
use App\Models\RecipeItem;
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

    /**
     * Ahead of batching ProductionCalculator's per-row recipe lookup into one
     * query, this locks down that each plan row's revenue/cost AND its
     * expanded raw-material requirements are keyed to that row's OWN item and
     * recipe — never another row's. Three rows, three different items: two
     * with distinct, non-overlapping recipes, and one with no recipe at all
     * (which must not error and must contribute nothing to materials).
     */
    public function test_plan_with_different_recipes_per_row_computes_materials_independently(): void
    {
        $tenant = $this->createTenant();
        $admin = $this->createMember($tenant, [TenantRole::Admin]);
        $this->actingAsTenant($tenant);

        $cork = InventoryItem::create([
            'name' => 'Cork', 'sku' => 'CRK', 'category' => 'RAW_MATERIAL', 'unit' => 'pcs',
            'sales_unit' => 'bottles', 'cost_per_unit' => 10,
        ]);
        $cap = InventoryItem::create([
            'name' => 'Cap', 'sku' => 'CAP', 'category' => 'RAW_MATERIAL', 'unit' => 'pcs',
            'sales_unit' => 'bottles', 'cost_per_unit' => 5,
        ]);
        $wineA = InventoryItem::create([
            'name' => 'Wine A', 'sku' => 'WA', 'category' => 'FINISHED', 'unit' => 'bottles',
            'sales_unit' => 'bottles', 'bottles_per_case' => 6, 'default_price' => 1000, 'cost_per_unit' => 200,
        ]);
        $wineB = InventoryItem::create([
            'name' => 'Wine B', 'sku' => 'WB', 'category' => 'FINISHED', 'unit' => 'bottles',
            'sales_unit' => 'bottles', 'bottles_per_case' => 6, 'default_price' => 2000, 'cost_per_unit' => 300,
        ]);
        $wineC = InventoryItem::create([
            'name' => 'Wine C (no recipe)', 'sku' => 'WC', 'category' => 'FINISHED', 'unit' => 'bottles',
            'sales_unit' => 'bottles', 'bottles_per_case' => 6, 'default_price' => 500, 'cost_per_unit' => 100,
        ]);

        RecipeItem::create(['output_id' => $wineA->getKey(), 'input_id' => $cork->getKey(), 'quantity' => '1']);
        RecipeItem::create(['output_id' => $wineB->getKey(), 'input_id' => $cap->getKey(), 'quantity' => '2']);
        // $wineC intentionally has no RecipeItem rows at all.

        $this->forgetTenant();

        Sanctum::actingAs($admin);

        $planId = $this->postJson('/api/v1/production-plans', ['name' => 'Mixed run'], $this->tenantHeader($tenant))->json('data.id');
        $this->patchJson("/api/v1/production-plans/{$planId}", [
            'rows' => [
                ['base_item_id' => $wineA->getKey(), 'quantity' => 10, 'plan_unit' => 'bottles'],
                ['base_item_id' => $wineB->getKey(), 'quantity' => 5, 'plan_unit' => 'bottles'],
                ['base_item_id' => $wineC->getKey(), 'quantity' => 3, 'plan_unit' => 'bottles'],
            ],
        ], $this->tenantHeader($tenant))->assertOk();

        $response = $this->getJson("/api/v1/production-plans/{$planId}/calculate", $this->tenantHeader($tenant))
            ->assertOk();

        $rows = $response->json('data.rows');
        $byItem = [];
        foreach ($rows as $row) {
            $byItem[$row['base_item_id']] = $row;
        }

        // Each row's revenue/cost is its OWN item's price × bottles — never
        // another row's price/cost leaking in.
        $this->assertSame(10000, $byItem[$wineA->getKey()]['revenue']); // 10 × €10.00
        $this->assertSame(2000, $byItem[$wineA->getKey()]['cost']); // 10 × €2.00
        $this->assertSame(10000, $byItem[$wineB->getKey()]['revenue']); // 5 × €20.00
        $this->assertSame(1500, $byItem[$wineB->getKey()]['cost']); // 5 × €3.00
        $this->assertSame(1500, $byItem[$wineC->getKey()]['revenue']); // 3 × €5.00 — no recipe, no error
        $this->assertSame(300, $byItem[$wineC->getKey()]['cost']); // 3 × €1.00

        $materials = $response->json('data.materials');
        $this->assertCount(2, $materials); // wineC contributed nothing

        $byName = [];
        foreach ($materials as $m) {
            $byName[$m['name']] = $m;
        }

        // Cork requirement comes only from Wine A's recipe (1 per bottle × 10).
        $this->assertEquals(10, $byName['Cork']['quantity']);
        $this->assertSame(100, $byName['Cork']['cost']); // 10 × €0.10

        // Cap requirement comes only from Wine B's recipe (2 per bottle × 5) —
        // a batching bug that reused Wine A's recipe for Wine B's row would
        // report Cork here instead, or the wrong quantity.
        $this->assertEquals(10, $byName['Cap']['quantity']);
        $this->assertSame(50, $byName['Cap']['cost']); // 10 × €0.05

        $response
            ->assertJsonPath('data.totals.revenue', 21500)
            ->assertJsonPath('data.totals.cost', 3800)
            ->assertJsonPath('data.totals.margin_pct', 82.3);
    }
}
