<?php

declare(strict_types=1);

namespace Tests\Feature\Web;

use App\Enums\TenantRole;
use App\Models\InventoryItem;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Auth\ActiveTenantSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\InteractsWithTenancy;
use Tests\TestCase;

/**
 * The Inertia inventory pages. These read through the same
 * ListInventoryItemsQuery + InventoryItemPresenter as the JSON API, so the
 * assertions here are about the page envelope and the capability gates, not a
 * second copy of the listing rules.
 */
class WebInventoryTest extends TestCase
{
    use InteractsWithTenancy;
    use RefreshDatabase;

    /** @return array{0: Tenant, 1: User} */
    private function tenantAndAdmin(): array
    {
        $tenant = $this->createTenant();
        $admin = $this->createMember($tenant, [TenantRole::Admin]);

        return [$tenant, $admin];
    }

    private function makeItem(string $name, string $sku): InventoryItem
    {
        return InventoryItem::create([
            'name' => $name,
            'sku' => $sku,
            'category' => 'RAW_MATERIAL',
            'unit' => 'units',
            'current_stock' => '10',
        ]);
    }

    public function test_index_renders_items_with_pagination_meta(): void
    {
        [$tenant, $admin] = $this->tenantAndAdmin();

        $this->actingAsTenant($tenant);
        $this->makeItem('Cork', 'CORK-1');
        $this->makeItem('Label', 'LBL-1');
        $this->forgetTenant();

        $this->actingAs($admin)
            ->withSession([ActiveTenantSession::KEY => $tenant->getKey()])
            ->get('/inventory')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Inventory/Index')
                ->has('items.data', 2)
                ->where('items.meta.total', 2)
                ->has('items.data.0.image_url')
                ->has('attention')
                ->where('filters.search', null));
    }

    public function test_index_filters_by_search(): void
    {
        [$tenant, $admin] = $this->tenantAndAdmin();

        $this->actingAsTenant($tenant);
        $this->makeItem('Cork', 'CORK-1');
        $this->makeItem('Label', 'LBL-1');
        $this->forgetTenant();

        $this->actingAs($admin)
            ->withSession([ActiveTenantSession::KEY => $tenant->getKey()])
            ->get('/inventory?search=Cork')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('items.data', 1)
                ->where('items.data.0.name', 'Cork')
                ->where('filters.search', 'Cork'));
    }

    public function test_taxonomy_is_only_sent_when_requested(): void
    {
        [$tenant, $admin] = $this->tenantAndAdmin();

        $this->actingAs($admin)
            ->withSession([ActiveTenantSession::KEY => $tenant->getKey()])
            ->get('/inventory')
            ->assertInertia(fn (AssertableInertia $page) => $page->missing('taxonomy'));
    }

    public function test_show_renders_a_single_item(): void
    {
        [$tenant, $admin] = $this->tenantAndAdmin();

        $this->actingAsTenant($tenant);
        $item = $this->makeItem('Cork', 'CORK-1');
        $this->forgetTenant();

        $this->actingAs($admin)
            ->withSession([ActiveTenantSession::KEY => $tenant->getKey()])
            ->get('/inventory/'.$item->getKey())
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Inventory/Show')
                ->where('item.sku', 'CORK-1'));
    }

    public function test_attention_band_counts_data_quality_conditions(): void
    {
        [$tenant, $admin] = $this->tenantAndAdmin();

        $this->actingAsTenant($tenant);
        // No min_stock and no cost_per_unit, and it has never moved.
        $this->makeItem('Cork', 'CORK-1');
        $this->forgetTenant();

        $this->actingAs($admin)
            ->withSession([ActiveTenantSession::KEY => $tenant->getKey()])
            ->get('/inventory')
            ->assertInertia(function (AssertableInertia $page) {
                $keys = collect($page->toArray()['props']['attention'])->pluck('key')->all();

                self::assertContains('no_min_stock', $keys);
                self::assertContains('no_cost_per_unit', $keys);
                self::assertContains('no_movement_90d', $keys);
            });
    }

    /**
     * The design's category tabs (Figma 389:1592) map to InventoryCategory.
     *
     * @return list<array{0: string}>
     */
    public static function designCategories(): array
    {
        return [['FINISHED'], ['SEMI_FINISHED'], ['RAW_MATERIAL']];
    }

    #[DataProvider('designCategories')]
    public function test_each_design_category_tab_filters(string $category): void
    {
        [$tenant, $admin] = $this->tenantAndAdmin();

        $this->actingAsTenant($tenant);
        $this->makeItem('Cork', 'CORK-1');   // RAW_MATERIAL
        $this->forgetTenant();

        $expected = $category === 'RAW_MATERIAL' ? 1 : 0;

        $this->actingAs($admin)
            ->withSession([ActiveTenantSession::KEY => $tenant->getKey()])
            ->get('/inventory?category='.$category)
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('items.data', $expected)
                ->where('filters.category', $category));
    }

    public function test_shared_props_expose_resolved_capabilities(): void
    {
        [$tenant, $admin] = $this->tenantAndAdmin();

        // ADMIN holds the wildcard, so the client's can() short-circuits to true.
        $this->actingAs($admin)
            ->withSession([ActiveTenantSession::KEY => $tenant->getKey()])
            ->get('/dashboard')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('auth.capabilities', ['*'])
                ->where('auth.roles', ['ADMIN'])
                ->where('tenant.id', $tenant->getKey())
                ->has('auth.user.email'));
    }

    public function test_member_without_the_capability_cannot_open_inventory(): void
    {
        $tenant = $this->createTenant();
        // HOSPITALITY grants nothing, so inventory.view must fail closed.
        $member = $this->createMember($tenant, [TenantRole::Hospitality]);

        $this->actingAs($member)
            ->withSession([ActiveTenantSession::KEY => $tenant->getKey()])
            ->get('/inventory')
            ->assertForbidden();
    }

    public function test_new_item_panel_creates_an_item(): void
    {
        [$tenant, $admin] = $this->tenantAndAdmin();

        $this->actingAs($admin)
            ->withSession([ActiveTenantSession::KEY => $tenant->getKey()])
            ->post('/inventory', [
                'name' => 'Plavac Mali 2022',
                'sku' => 'VT-PM-22',
                'category' => 'FINISHED',
                'group' => 'Wine',
                'unit_size' => '750 ml',
                'unit' => 'bottles',
                // Money reaches the server as integer minor units.
                'default_price' => 1850,
                'sales_unit' => 'bottles',
                'bottles_per_case' => 6,
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->actingAsTenant($tenant);
        $item = InventoryItem::query()->where('sku', 'VT-PM-22')->first();
        self::assertNotNull($item);
        self::assertSame('Plavac Mali 2022', $item->name);
        self::assertSame('Wine', $item->group);
        $this->forgetTenant();
    }

    public function test_creating_an_item_requires_the_manage_capability(): void
    {
        $tenant = $this->createTenant();
        // SALES grants finance only — no inventory.manage.
        $member = $this->createMember($tenant, [TenantRole::Sales]);

        $this->actingAs($member)
            ->withSession([ActiveTenantSession::KEY => $tenant->getKey()])
            ->post('/inventory', ['name' => 'X', 'sku' => 'X-1', 'category' => 'FINISHED', 'unit' => 'units'])
            ->assertForbidden();
    }

    public function test_delete_deactivates_an_item_referenced_by_orders(): void
    {
        [$tenant, $admin] = $this->tenantAndAdmin();

        $this->actingAsTenant($tenant);
        $item = $this->makeItem('Cork', 'CORK-1');
        $this->forgetTenant();

        $this->actingAs($admin)
            ->withSession([ActiveTenantSession::KEY => $tenant->getKey()])
            ->delete('/inventory/'.$item->getKey())
            ->assertRedirect('/inventory')
            ->assertSessionHas('success');

        // No order lines reference it, so it is hard-deleted.
        $this->actingAsTenant($tenant);
        $this->assertNull(InventoryItem::query()->find($item->getKey()));
        $this->forgetTenant();
    }
}
