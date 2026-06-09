<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Enums\TenantRole;
use App\Models\InventoryItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\InteractsWithTenancy;
use Tests\TestCase;

class StockTest extends TestCase
{
    use InteractsWithTenancy;
    use RefreshDatabase;

    private function item(): InventoryItem
    {
        return InventoryItem::create([
            'name' => 'Cork',
            'sku' => 'CORK',
            'category' => 'RAW_MATERIAL',
            'unit' => 'units',
            'current_stock' => '100.000',
        ]);
    }

    public function test_adjusting_stock_records_a_movement_and_updates_running_total(): void
    {
        $tenant = $this->createTenant();
        $admin = $this->createMember($tenant, [TenantRole::Admin]);
        $this->actingAsTenant($tenant);
        $item = $this->item();
        $this->forgetTenant();

        Sanctum::actingAs($admin);

        $this->postJson("/api/v1/inventory-items/{$item->getKey()}/stock", [
            'type' => 'MANUAL_IN',
            'quantity' => '25.5',
            'reference' => 'PO-1',
        ], $this->tenantHeader($tenant))
            ->assertOk()
            ->assertJsonPath('data.current_stock', '125.500');

        $this->postJson("/api/v1/inventory-items/{$item->getKey()}/stock", [
            'type' => 'MANUAL_OUT',
            'quantity' => '-30',
        ], $this->tenantHeader($tenant))
            ->assertOk()
            ->assertJsonPath('data.current_stock', '95.500');

        $this->assertDatabaseHas('stock_movements', [
            'inventory_item_id' => $item->getKey(),
            'reference' => 'PO-1',
        ]);
        $this->assertSame(2, $item->stockMovements()->count());
    }

    public function test_recipe_can_be_set_for_an_item(): void
    {
        $tenant = $this->createTenant();
        $admin = $this->createMember($tenant, [TenantRole::Admin]);
        $this->actingAsTenant($tenant);
        $bottle = InventoryItem::create(['name' => 'Wine', 'sku' => 'WINE', 'category' => 'FINISHED', 'unit' => 'bottles']);
        $cork = InventoryItem::create(['name' => 'Cork', 'sku' => 'CORK', 'category' => 'RAW_MATERIAL', 'unit' => 'units']);
        $this->forgetTenant();

        Sanctum::actingAs($admin);

        $this->putJson("/api/v1/inventory-items/{$bottle->getKey()}/recipe", [
            'items' => [
                ['input_id' => $cork->getKey(), 'quantity' => '1'],
            ],
        ], $this->tenantHeader($tenant))
            ->assertOk()
            ->assertJsonPath('data.0.input_id', $cork->getKey());

        $this->assertDatabaseHas('recipe_items', [
            'output_id' => $bottle->getKey(),
            'input_id' => $cork->getKey(),
        ]);
    }

    public function test_an_item_cannot_be_its_own_ingredient(): void
    {
        $tenant = $this->createTenant();
        $admin = $this->createMember($tenant, [TenantRole::Admin]);
        $this->actingAsTenant($tenant);
        $item = $this->item();
        $this->forgetTenant();

        Sanctum::actingAs($admin);

        $this->putJson("/api/v1/inventory-items/{$item->getKey()}/recipe", [
            'items' => [['input_id' => $item->getKey(), 'quantity' => '1']],
        ], $this->tenantHeader($tenant))->assertStatus(422);
    }

    public function test_movements_are_listed_newest_first(): void
    {
        $tenant = $this->createTenant();
        $admin = $this->createMember($tenant, [TenantRole::Admin]);
        $this->actingAsTenant($tenant);
        $item = $this->item();
        $this->forgetTenant();

        Sanctum::actingAs($admin);

        $this->postJson("/api/v1/inventory-items/{$item->getKey()}/stock", [
            'type' => 'MANUAL_IN', 'quantity' => '10', 'reference' => 'FIRST',
        ], $this->tenantHeader($tenant))->assertOk();
        $this->postJson("/api/v1/inventory-items/{$item->getKey()}/stock", [
            'type' => 'MANUAL_OUT', 'quantity' => '-4', 'reference' => 'SECOND',
        ], $this->tenantHeader($tenant))->assertOk();

        $this->getJson("/api/v1/inventory-items/{$item->getKey()}/movements", $this->tenantHeader($tenant))
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.reference', 'SECOND')
            ->assertJsonPath('data.0.type', 'MANUAL_OUT')
            ->assertJsonPath('data.1.reference', 'FIRST');
    }

    public function test_recipe_is_returned_with_input_details(): void
    {
        $tenant = $this->createTenant();
        $admin = $this->createMember($tenant, [TenantRole::Admin]);
        $this->actingAsTenant($tenant);
        $bottle = InventoryItem::create(['name' => 'Wine', 'sku' => 'WINE', 'category' => 'FINISHED', 'unit' => 'bottles']);
        $cork = InventoryItem::create(['name' => 'Cork', 'sku' => 'CORK', 'category' => 'RAW_MATERIAL', 'unit' => 'units']);
        $this->forgetTenant();

        Sanctum::actingAs($admin);

        $this->putJson("/api/v1/inventory-items/{$bottle->getKey()}/recipe", [
            'items' => [['input_id' => $cork->getKey(), 'quantity' => '2']],
        ], $this->tenantHeader($tenant))->assertOk();

        $this->getJson("/api/v1/inventory-items/{$bottle->getKey()}/recipe", $this->tenantHeader($tenant))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.input_id', $cork->getKey())
            ->assertJsonPath('data.0.input_name', 'Cork')
            ->assertJsonPath('data.0.input_sku', 'CORK')
            ->assertJsonPath('data.0.input_unit', 'units')
            ->assertJsonPath('data.0.quantity', '2.000');
    }
}
