<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Enums\TenantRole;
use App\Models\InventoryItem;
use App\Models\RecipeItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\InteractsWithTenancy;
use Tests\TestCase;

class ProduceAndCheckTest extends TestCase
{
    use InteractsWithTenancy;
    use RefreshDatabase;

    public function test_producing_from_recipe_consumes_inputs_and_adds_output(): void
    {
        $tenant = $this->createTenant();
        $admin = $this->createMember($tenant, [TenantRole::Admin]);
        $this->actingAsTenant($tenant);
        $wine = InventoryItem::create(['name' => 'Wine', 'sku' => 'WINE', 'category' => 'FINISHED', 'unit' => 'bottles', 'current_stock' => '0.000']);
        $cork = InventoryItem::create(['name' => 'Cork', 'sku' => 'CORK', 'category' => 'RAW_MATERIAL', 'unit' => 'units', 'current_stock' => '100.000']);
        RecipeItem::create(['output_id' => $wine->getKey(), 'input_id' => $cork->getKey(), 'quantity' => '1']);
        $this->forgetTenant();

        Sanctum::actingAs($admin);

        $this->postJson("/api/v1/inventory-items/{$wine->getKey()}/produce", [
            'display_quantity' => '10',
        ], $this->tenantHeader($tenant))
            ->assertOk()
            ->assertJsonPath('data.current_stock', '10.000');

        $this->assertSame('90.000', (string) $cork->refresh()->current_stock);
        $this->assertDatabaseHas('stock_movements', [
            'inventory_item_id' => $wine->getKey(), 'type' => 'PRODUCTION_IN', 'reference' => 'PROD-WINE',
        ]);
        $this->assertDatabaseHas('stock_movements', [
            'inventory_item_id' => $cork->getKey(), 'type' => 'PRODUCTION_OUT', 'reference' => 'PROD-WINE',
        ]);
    }

    public function test_producing_is_blocked_when_inputs_are_short(): void
    {
        $tenant = $this->createTenant();
        $admin = $this->createMember($tenant, [TenantRole::Admin]);
        $this->actingAsTenant($tenant);
        $wine = InventoryItem::create(['name' => 'Wine', 'sku' => 'WINE', 'category' => 'FINISHED', 'unit' => 'bottles']);
        $cork = InventoryItem::create(['name' => 'Cork', 'sku' => 'CORK', 'category' => 'RAW_MATERIAL', 'unit' => 'units', 'current_stock' => '5.000']);
        RecipeItem::create(['output_id' => $wine->getKey(), 'input_id' => $cork->getKey(), 'quantity' => '1']);
        $this->forgetTenant();

        Sanctum::actingAs($admin);

        $this->postJson("/api/v1/inventory-items/{$wine->getKey()}/produce", [
            'display_quantity' => '10',
        ], $this->tenantHeader($tenant))->assertStatus(422);

        // Nothing produced, nothing consumed.
        $this->assertSame('5.000', (string) $cork->refresh()->current_stock);
        $this->assertSame('0.000', (string) $wine->refresh()->current_stock);
        $this->assertSame(0, $wine->stockMovements()->count());
    }

    public function test_producing_without_a_recipe_is_rejected(): void
    {
        $tenant = $this->createTenant();
        $admin = $this->createMember($tenant, [TenantRole::Admin]);
        $this->actingAsTenant($tenant);
        $item = InventoryItem::create(['name' => 'Wine', 'sku' => 'WINE', 'category' => 'FINISHED', 'unit' => 'bottles']);
        $this->forgetTenant();

        Sanctum::actingAs($admin);

        $this->postJson("/api/v1/inventory-items/{$item->getKey()}/produce", [
            'display_quantity' => '10',
        ], $this->tenantHeader($tenant))->assertStatus(422);
    }

    public function test_inventory_check_writes_reconciliation_adjustments(): void
    {
        $tenant = $this->createTenant();
        $admin = $this->createMember($tenant, [TenantRole::Admin]);
        $this->actingAsTenant($tenant);
        $a = InventoryItem::create(['name' => 'A', 'sku' => 'A', 'category' => 'FINISHED', 'unit' => 'bottles', 'current_stock' => '100.000']);
        $b = InventoryItem::create(['name' => 'B', 'sku' => 'B', 'category' => 'FINISHED', 'unit' => 'bottles', 'current_stock' => '50.000']);
        $this->forgetTenant();

        Sanctum::actingAs($admin);

        $this->postJson('/api/v1/inventory-items/check', [
            'items' => [
                ['item_id' => $a->getKey(), 'physical_count' => '90'],   // -10
                ['item_id' => $b->getKey(), 'physical_count' => '50'],   // no change
            ],
        ], $this->tenantHeader($tenant))
            ->assertOk()
            ->assertJsonPath('data.0.difference', '-10.000')
            ->assertJsonPath('data.1.difference', '0.000');

        $this->assertSame('90.000', (string) $a->refresh()->current_stock);
        $this->assertSame('50.000', (string) $b->refresh()->current_stock);

        // A reconciliation adjustment for A; none for B (no difference).
        $this->assertSame(1, $a->stockMovements()->where('type', 'ADJUSTMENT')->where('is_reconciliation', true)->count());
        $this->assertSame(0, $b->stockMovements()->count());
    }

    public function test_reconciliation_flag_can_be_toggled_on_a_movement(): void
    {
        $tenant = $this->createTenant();
        $admin = $this->createMember($tenant, [TenantRole::Admin]);
        $this->actingAsTenant($tenant);
        $item = InventoryItem::create(['name' => 'A', 'sku' => 'A', 'category' => 'FINISHED', 'unit' => 'bottles', 'current_stock' => '10.000']);
        $this->forgetTenant();

        Sanctum::actingAs($admin);

        $this->postJson("/api/v1/inventory-items/{$item->getKey()}/stock", [
            'type' => 'MANUAL_OUT', 'quantity' => '-2',
        ], $this->tenantHeader($tenant))->assertOk();

        // The adjust response returns the item; fetch the movement from the ledger.
        $movement = $item->stockMovements()->firstOrFail();

        $this->patchJson("/api/v1/stock-movements/{$movement->getKey()}/reconciliation", [
            'is_reconciliation' => true,
        ], $this->tenantHeader($tenant))
            ->assertOk()
            ->assertJsonPath('data.is_reconciliation', true);
    }
}
