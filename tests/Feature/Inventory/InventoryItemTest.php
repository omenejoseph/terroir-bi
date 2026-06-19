<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Enums\TenantRole;
use App\Models\InventoryItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\InteractsWithTenancy;
use Tests\TestCase;

class InventoryItemTest extends TestCase
{
    use InteractsWithTenancy;
    use RefreshDatabase;

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Plavac Mali 2021',
            'sku' => 'PM-2021',
            'category' => 'FINISHED',
            'unit' => 'bottles',
            'sales_unit' => 'bottles',
            'bottles_per_case' => 12,
            'cost_per_unit' => 800,
            'default_price' => 1999, // €19.99 in minor units
            'is_for_sale' => true,
        ], $overrides);
    }

    public function test_team_member_can_create_an_item_with_money_cast(): void
    {
        $tenant = $this->createTenant();
        Sanctum::actingAs($this->createMember($tenant, [TenantRole::Team]));

        $this->postJson('/api/v1/inventory-items', $this->payload(), $this->tenantHeader($tenant))
            ->assertCreated()
            ->assertJsonPath('data.sku', 'PM-2021')
            ->assertJsonPath('data.default_price.minor', 1999)
            ->assertJsonPath('data.default_price.currency', 'EUR')
            ->assertJsonPath('data.default_price.formatted', '19.99');
    }

    public function test_cellar_member_cannot_manage_inventory(): void
    {
        $tenant = $this->createTenant();
        Sanctum::actingAs($this->createMember($tenant, [TenantRole::Cellar]));

        $this->postJson('/api/v1/inventory-items', $this->payload(), $this->tenantHeader($tenant))
            ->assertForbidden();
    }

    public function test_sku_is_unique_per_tenant(): void
    {
        $a = $this->createTenant();
        $admin = $this->createMember($a, [TenantRole::Admin]);
        $b = $this->createTenant();
        $this->createMembershipFor($admin, $b, [TenantRole::Admin]);

        Sanctum::actingAs($admin);

        $this->postJson('/api/v1/inventory-items', $this->payload(), $this->tenantHeader($a))->assertCreated();

        // Same SKU under another tenant is fine.
        $this->postJson('/api/v1/inventory-items', $this->payload(), $this->tenantHeader($b))->assertCreated();

        // Duplicate within the same tenant is rejected.
        $this->postJson('/api/v1/inventory-items', $this->payload(), $this->tenantHeader($a))
            ->assertStatus(422)
            ->assertJsonValidationErrorFor('sku');
    }

    public function test_items_are_isolated_per_tenant(): void
    {
        $a = $this->createTenant();
        $admin = $this->createMember($a, [TenantRole::Admin]);
        $this->actingAsTenant($a);
        $itemA = InventoryItem::create($this->payload());
        $this->forgetTenant();

        $b = $this->createTenant();
        $this->createMembershipFor($admin, $b, [TenantRole::Admin]);

        Sanctum::actingAs($admin);
        $this->getJson("/api/v1/inventory-items/{$itemA->getKey()}", $this->tenantHeader($b))
            ->assertNotFound();

        $this->getJson('/api/v1/inventory-items', $this->tenantHeader($b))
            ->assertOk()
            ->assertJsonPath('meta.total', 0);
    }

    public function test_sellable_filter_returns_only_finished_for_sale_priced_items(): void
    {
        $tenant = $this->createTenant();
        $admin = $this->createMember($tenant, [TenantRole::Admin]);
        $this->actingAsTenant($tenant);
        InventoryItem::create($this->payload(['sku' => 'A', 'is_for_sale' => true, 'default_price' => 1000]));
        InventoryItem::create($this->payload(['sku' => 'B', 'is_for_sale' => false, 'default_price' => 1000]));
        InventoryItem::create($this->payload(['sku' => 'C', 'category' => 'RAW_MATERIAL', 'default_price' => null]));
        $this->forgetTenant();

        Sanctum::actingAs($admin);
        $this->getJson('/api/v1/inventory-items?sellable=1', $this->tenantHeader($tenant))
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.sku', 'A');
    }

    public function test_admin_can_delete_an_item_but_team_cannot(): void
    {
        $tenant = $this->createTenant();
        $admin = $this->createMember($tenant, [TenantRole::Admin]);
        $team = $this->createMember($tenant, [TenantRole::Team]);
        $this->actingAsTenant($tenant);
        $item = InventoryItem::create($this->payload());
        $this->forgetTenant();

        Sanctum::actingAs($team);
        $this->deleteJson("/api/v1/inventory-items/{$item->getKey()}", [], $this->tenantHeader($tenant))
            ->assertForbidden();

        Sanctum::actingAs($admin);
        $this->deleteJson("/api/v1/inventory-items/{$item->getKey()}", [], $this->tenantHeader($tenant))
            ->assertNoContent();

        $this->assertDatabaseMissing('inventory_items', ['id' => $item->getKey()]);
    }

    public function test_taxonomy_returns_distinct_category_group_subcategory_combos(): void
    {
        $tenant = $this->createTenant();
        $admin = $this->createMember($tenant, [TenantRole::Admin]);
        $this->actingAsTenant($tenant);
        InventoryItem::create($this->payload(['sku' => 'A', 'group' => 'Wine', 'subcategory' => 'Red']));
        InventoryItem::create($this->payload(['sku' => 'B', 'group' => 'Wine', 'subcategory' => 'Packaging']));
        // Duplicate combo of the first — must be collapsed.
        InventoryItem::create($this->payload(['sku' => 'C', 'group' => 'Wine', 'subcategory' => 'Red']));
        // No group — excluded from the taxonomy.
        InventoryItem::create($this->payload(['sku' => 'D']));
        $this->forgetTenant();

        Sanctum::actingAs($admin);

        $this->getJson('/api/v1/inventory-items/taxonomy', $this->tenantHeader($tenant))
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonFragment(['category' => 'FINISHED', 'group' => 'Wine', 'subcategory' => 'Red'])
            ->assertJsonFragment(['category' => 'FINISHED', 'group' => 'Wine', 'subcategory' => 'Packaging']);
    }

    public function test_packaged_item_requires_sales_unit_and_bottles_per_case_but_not_cost(): void
    {
        $tenant = $this->createTenant();
        Sanctum::actingAs($this->createMember($tenant, [TenantRole::Team]));

        // unit 'bottles' is packaged; cost is optional (can come from a recipe).
        $payload = $this->payload();
        unset($payload['sales_unit'], $payload['cost_per_unit'], $payload['bottles_per_case']);

        $this->postJson('/api/v1/inventory-items', $payload, $this->tenantHeader($tenant))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['sales_unit', 'bottles_per_case'])
            ->assertJsonMissingValidationErrors(['cost_per_unit']);
    }

    public function test_item_can_be_created_without_a_cost(): void
    {
        $tenant = $this->createTenant();
        Sanctum::actingAs($this->createMember($tenant, [TenantRole::Admin]));

        $payload = $this->payload(['sku' => 'NOCOST-1']);
        unset($payload['cost_per_unit']);

        $this->postJson('/api/v1/inventory-items', $payload, $this->tenantHeader($tenant))
            ->assertCreated()
            ->assertJsonPath('data.cost_per_unit', null);
    }

    public function test_non_packaged_unit_does_not_require_sales_unit_or_bottles_per_case(): void
    {
        $tenant = $this->createTenant();
        Sanctum::actingAs($this->createMember($tenant, [TenantRole::Admin]));

        // A bulk item measured in litres: no sales_unit / bottles_per_case needed.
        $payload = $this->payload(['sku' => 'BULK-1', 'unit' => 'litre']);
        unset($payload['sales_unit'], $payload['bottles_per_case']);

        $this->postJson('/api/v1/inventory-items', $payload, $this->tenantHeader($tenant))
            ->assertCreated()
            ->assertJsonPath('data.unit', 'litre');
    }

    public function test_stock_is_not_editable_via_update(): void
    {
        $tenant = $this->createTenant();
        Sanctum::actingAs($this->createMember($tenant, [TenantRole::Admin]));

        $id = $this->postJson('/api/v1/inventory-items', $this->payload(), $this->tenantHeader($tenant))
            ->assertCreated()->json('data.id');

        // current_stock is derived from movements — the update endpoint ignores it.
        $this->patchJson("/api/v1/inventory-items/{$id}", ['current_stock' => '999'], $this->tenantHeader($tenant))
            ->assertOk()
            ->assertJsonPath('data.current_stock', '0.000');
    }

    public function test_cost_can_be_cleared_on_update(): void
    {
        $tenant = $this->createTenant();
        Sanctum::actingAs($this->createMember($tenant, [TenantRole::Admin]));

        $id = $this->postJson('/api/v1/inventory-items', $this->payload(), $this->tenantHeader($tenant))
            ->assertCreated()->json('data.id');

        // Clearing the cost is allowed — COGS can fall back to the recipe.
        $this->patchJson("/api/v1/inventory-items/{$id}", ['cost_per_unit' => null], $this->tenantHeader($tenant))
            ->assertOk()
            ->assertJsonPath('data.cost_per_unit', null);
    }

    public function test_duplicate_clones_item_with_new_sku_zero_stock_and_recipe(): void
    {
        $tenant = $this->createTenant();
        $admin = $this->createMember($tenant, [TenantRole::Team]);
        $this->actingAsTenant($tenant);
        $input = InventoryItem::create(['name' => 'Juice', 'sku' => 'JU-1', 'category' => 'RAW_MATERIAL', 'unit' => 'liter', 'current_stock' => '50']);
        $output = InventoryItem::create(['name' => 'Cuvée', 'sku' => 'CV-1', 'category' => 'FINISHED', 'unit' => 'bottles', 'sales_unit' => 'bottles', 'bottles_per_case' => 6, 'current_stock' => '120', 'default_price' => 2500]);
        $output->recipe()->create(['input_id' => $input->getKey(), 'quantity' => '0.750']);
        $this->forgetTenant();

        Sanctum::actingAs($admin);
        $newId = $this->postJson("/api/v1/inventory-items/{$output->getKey()}/duplicate", [], $this->tenantHeader($tenant))
            ->assertCreated()
            ->assertJsonPath('data.name', 'Cuvée (Copy)')
            ->assertJsonPath('data.sku', 'CV-1-COPY')
            ->assertJsonPath('data.current_stock', '0.000')          // stock never copied
            ->assertJsonPath('data.default_price.minor', 2500)        // other fields copied
            ->json('data.id');

        $this->assertDatabaseHas('recipe_items', ['output_id' => $newId, 'input_id' => $input->getKey()]);

        // A second duplicate bumps the suffix rather than colliding on the SKU.
        $this->postJson("/api/v1/inventory-items/{$output->getKey()}/duplicate", [], $this->tenantHeader($tenant))
            ->assertCreated()
            ->assertJsonPath('data.sku', 'CV-1-COPY-2');
    }

    public function test_bulk_update_applies_edits_to_many_items(): void
    {
        $tenant = $this->createTenant();
        $admin = $this->createMember($tenant, [TenantRole::Team]);
        $this->actingAsTenant($tenant);
        $a = InventoryItem::create(['name' => 'A', 'sku' => 'A', 'category' => 'FINISHED', 'unit' => 'bottles', 'is_active' => true, 'default_price' => 1000]);
        $b = InventoryItem::create(['name' => 'B', 'sku' => 'B', 'category' => 'FINISHED', 'unit' => 'bottles', 'is_active' => true, 'is_for_sale' => false]);
        $this->forgetTenant();

        Sanctum::actingAs($admin);
        $this->postJson('/api/v1/inventory-items/bulk-update', ['items' => [
            ['id' => $a->getKey(), 'name' => 'A renamed', 'default_price' => 1500, 'is_active' => false],
            ['id' => $b->getKey(), 'is_for_sale' => true],
        ]], $this->tenantHeader($tenant))
            ->assertOk()
            ->assertJsonPath('data.updated', 2);

        $this->assertDatabaseHas('inventory_items', ['id' => $a->getKey(), 'name' => 'A renamed', 'default_price' => 1500, 'is_active' => false]);
        $this->assertDatabaseHas('inventory_items', ['id' => $b->getKey(), 'is_for_sale' => true]);
    }
}
