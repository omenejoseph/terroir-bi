<?php

declare(strict_types=1);

namespace Tests\Feature\Web;

use App\Enums\TenantRole;
use App\Models\InventoryItem;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Auth\ActiveTenantSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Inertia;
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

    public function test_item_movements_are_only_sent_when_the_drawer_asks(): void
    {
        [$tenant, $admin] = $this->tenantAndAdmin();

        $this->actingAsTenant($tenant);
        $item = $this->makeItem('Cork', 'CORK-1');
        $this->forgetTenant();

        // A plain visit must not pay for movements nobody opened.
        $this->actingAs($admin)
            ->withSession([ActiveTenantSession::KEY => $tenant->getKey()])
            ->get('/inventory')
            ->assertInertia(fn (AssertableInertia $page) => $page->missing('itemMovements'));

        // The drawer's partial reload asks for them by item. A partial request
        // answers with the raw page object, so it is read as JSON rather than
        // through assertInertia (which expects a full page response).
        $response = $this->actingAs($admin)
            ->withSession([ActiveTenantSession::KEY => $tenant->getKey()])
            ->get('/inventory?item='.$item->getKey(), [
                'X-Inertia' => 'true',
                // A wrong version makes Inertia answer 409 rather than the page.
                'X-Inertia-Version' => (string) Inertia::getVersion(),
                'X-Inertia-Partial-Component' => 'Inventory/Index',
                'X-Inertia-Partial-Data' => 'itemMovements',
            ])
            ->assertOk();

        $props = $response->json('props');
        self::assertIsArray($props);
        self::assertArrayHasKey('itemMovements', $props);
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

    public function test_show_carries_stock_analytics_and_movements(): void
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
                ->where('item.sku', 'CORK-1')
                ->has('analytics.current')
                ->has('analytics.exits.spark')
                ->has('analytics.realized')
                ->has('analytics.channels')
                ->has('movements')
                ->where('filters.period', '30d'));
    }

    /**
     * Every tab in the design's exit-period strip (Figma 449:1577) must resolve
     * to a real window in InventoryItemStockAnalyticsQuery.
     *
     * @return list<array{0: string}>
     */
    public static function designStockPeriods(): array
    {
        return [['today'], ['mtd'], ['ytd'], ['30d'], ['90d']];
    }

    #[DataProvider('designStockPeriods')]
    public function test_each_design_stock_period_resolves(string $period): void
    {
        [$tenant, $admin] = $this->tenantAndAdmin();

        $this->actingAsTenant($tenant);
        $item = $this->makeItem('Cork', 'CORK-1');
        $this->forgetTenant();

        $this->actingAs($admin)
            ->withSession([ActiveTenantSession::KEY => $tenant->getKey()])
            ->get('/inventory/'.$item->getKey().'?period='.$period)
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('filters.period', $period));
    }

    public function test_quick_stock_entry_records_a_movement_with_running_balance(): void
    {
        [$tenant, $admin] = $this->tenantAndAdmin();

        $this->actingAsTenant($tenant);
        $item = $this->makeItem('Cork', 'CORK-1'); // starts at 10
        $this->forgetTenant();

        $this->actingAs($admin)
            ->withSession([ActiveTenantSession::KEY => $tenant->getKey()])
            ->post('/inventory/'.$item->getKey().'/stock', [
                'type' => 'MANUAL_OUT',
                'quantity' => '-4',
                'note' => 'Broken case',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->actingAsTenant($tenant);
        self::assertSame('6.000', (string) $item->refresh()->current_stock);
        $this->forgetTenant();

        // The balance column is derived, and must land on the post-movement stock.
        $this->actingAs($admin)
            ->withSession([ActiveTenantSession::KEY => $tenant->getKey()])
            ->get('/inventory/'.$item->getKey())
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('movements', 1)
                ->where('movements.0.balance', '6')
                ->where('movements.0.note', 'Broken case'));
    }

    public function test_quick_stock_entry_requires_the_manage_capability(): void
    {
        $tenant = $this->createTenant();
        $member = $this->createMember($tenant, [TenantRole::Sales]);
        $this->actingAsTenant($tenant);
        $item = $this->makeItem('Cork', 'CORK-1');
        $this->forgetTenant();

        $this->actingAs($member)
            ->withSession([ActiveTenantSession::KEY => $tenant->getKey()])
            ->post('/inventory/'.$item->getKey().'/stock', ['type' => 'MANUAL_IN', 'quantity' => '1'])
            ->assertForbidden();
    }

    public function test_bulk_update_writes_only_the_fields_sent(): void
    {
        [$tenant, $admin] = $this->tenantAndAdmin();

        $this->actingAsTenant($tenant);
        $item = $this->makeItem('Cork', 'CORK-1');
        $item->update(['min_stock' => '5']);
        $this->forgetTenant();

        $this->actingAs($admin)
            ->withSession([ActiveTenantSession::KEY => $tenant->getKey()])
            ->patch('/inventory-bulk', [
                'items' => [['id' => $item->getKey(), 'name' => 'Cork Natural']],
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->actingAsTenant($tenant);
        $item->refresh();
        self::assertSame('Cork Natural', $item->name);
        // min_stock was not in the payload, so it must be untouched.
        self::assertSame('5.000', (string) $item->min_stock);
        $this->forgetTenant();
    }

    public function test_bulk_update_requires_the_manage_capability(): void
    {
        $tenant = $this->createTenant();
        $member = $this->createMember($tenant, [TenantRole::Sales]);
        $this->actingAsTenant($tenant);
        $item = $this->makeItem('Cork', 'CORK-1');
        $this->forgetTenant();

        $this->actingAs($member)
            ->withSession([ActiveTenantSession::KEY => $tenant->getKey()])
            ->patch('/inventory-bulk', ['items' => [['id' => $item->getKey(), 'name' => 'X']]])
            ->assertForbidden();
    }

    public function test_analytics_page_renders(): void
    {
        [$tenant, $admin] = $this->tenantAndAdmin();

        $this->actingAsTenant($tenant);
        $this->makeItem('Cork', 'CORK-1');
        $this->forgetTenant();

        $this->actingAs($admin)
            ->withSession([ActiveTenantSession::KEY => $tenant->getKey()])
            ->get('/inventory-analytics')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Inventory/Analytics')
                ->has('analytics.summary.finished_units')
                ->has('analytics.summary.costed_count')
                ->has('analytics.movements_12m')
                ->has('analytics.portfolio_exits.channels')
                ->has('analytics.value.categories'));
    }

    public function test_analytics_requires_the_view_capability(): void
    {
        $tenant = $this->createTenant();
        $member = $this->createMember($tenant, [TenantRole::Hospitality]);

        $this->actingAs($member)
            ->withSession([ActiveTenantSession::KEY => $tenant->getKey()])
            ->get('/inventory-analytics')
            ->assertForbidden();
    }

    public function test_spend_page_renders(): void
    {
        [$tenant, $admin] = $this->tenantAndAdmin();

        $this->actingAsTenant($tenant);
        $this->makeItem('Cork', 'CORK-1');
        $this->forgetTenant();

        $this->actingAs($admin)
            ->withSession([ActiveTenantSession::KEY => $tenant->getKey()])
            ->get('/inventory-spend')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Inventory/Spend')
                ->has('spend.summary')
                ->has('spend.daily')
                ->has('spend.per_product')
                ->has('portfolio.value')
                ->where('spend.period.days', 91)
                ->where('filters.preset', '90d'));
    }

    public function test_spend_requires_the_financials_capability(): void
    {
        $tenant = $this->createTenant();
        // CELLAR can see inventory but not money.
        $member = $this->createMember($tenant, [TenantRole::Cellar]);

        $this->actingAs($member)
            ->withSession([ActiveTenantSession::KEY => $tenant->getKey()])
            ->get('/inventory-spend')
            ->assertForbidden();
    }

    public function test_check_page_lists_active_items(): void
    {
        [$tenant, $admin] = $this->tenantAndAdmin();

        $this->actingAsTenant($tenant);
        $this->makeItem('Cork', 'CORK-1');
        $this->forgetTenant();

        $this->actingAs($admin)
            ->withSession([ActiveTenantSession::KEY => $tenant->getKey()])
            ->get('/inventory-check')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Inventory/Check')
                ->has('items', 1)
                ->has('history'));
    }

    public function test_applying_a_check_writes_a_reconciliation_movement(): void
    {
        [$tenant, $admin] = $this->tenantAndAdmin();

        $this->actingAsTenant($tenant);
        $item = $this->makeItem('Cork', 'CORK-1'); // system stock 10
        $this->forgetTenant();

        $this->actingAs($admin)
            ->withSession([ActiveTenantSession::KEY => $tenant->getKey()])
            ->post('/inventory-check', [
                'items' => [['item_id' => $item->getKey(), 'physical_count' => '7']],
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->actingAsTenant($tenant);
        self::assertSame('7.000', (string) $item->refresh()->current_stock);

        // The adjustment must be flagged as a reconciliation so it is excluded
        // from velocity and cover.
        $movement = $item->stockMovements()->latest('id')->first();
        self::assertNotNull($movement);
        self::assertTrue($movement->is_reconciliation);
        self::assertSame('-3.000', (string) $movement->quantity);
        $this->forgetTenant();
    }

    public function test_applying_a_check_requires_the_manage_capability(): void
    {
        $tenant = $this->createTenant();
        $member = $this->createMember($tenant, [TenantRole::Sales]);
        $this->actingAsTenant($tenant);
        $item = $this->makeItem('Cork', 'CORK-1');
        $this->forgetTenant();

        $this->actingAs($member)
            ->withSession([ActiveTenantSession::KEY => $tenant->getKey()])
            ->post('/inventory-check', ['items' => [['item_id' => $item->getKey(), 'physical_count' => '1']]])
            ->assertForbidden();
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
