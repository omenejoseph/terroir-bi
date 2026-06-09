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
}
