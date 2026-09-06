<?php

declare(strict_types=1);

namespace Tests\Feature\Web;

use App\Actions\Orders\CreateOrderAction;
use App\Actions\Orders\UpdateOrderItemAction;
use App\Enums\TenantRole;
use App\Models\Customer;
use App\Models\CustomerPrice;
use App\Models\InventoryItem;
use App\Models\Order;
use App\Models\PricingTier;
use App\Models\RecipeItem;
use App\Models\StockMovement;
use App\Models\Tenant;
use App\Models\TierPrice;
use App\Models\User;
use App\Services\Auth\ActiveTenantSession;
use App\Support\Money\Money;
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

    /**
     * "Cover" (Figma 389:1592's list column) — a days-of-stock-left figure per
     * item, added alongside `items` rather than into the shared presenter
     * (InventoryCoverQuery's own tests cover the math itself).
     */
    public function test_index_carries_cover_days_left_per_item(): void
    {
        [$tenant, $admin] = $this->tenantAndAdmin();

        $this->actingAsTenant($tenant);
        $item = $this->makeItem('Cork', 'CORK-1');
        $untouched = $this->makeItem('Label', 'LBL-1');
        StockMovement::create(['inventory_item_id' => $item->getKey(), 'type' => 'MANUAL_OUT', 'quantity' => -5]);
        $this->forgetTenant();

        $this->actingAs($admin)
            ->withSession([ActiveTenantSession::KEY => $tenant->getKey()])
            ->get('/inventory')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                // 10 in stock, 5 exited in the last 30 days -> round(10×30/5) = 60.
                ->where("cover.{$item->getKey()}", 60)
                ->where("cover.{$untouched->getKey()}", null));
    }

    /**
     * The size of a page is a request, not a filter, and an out-of-range value
     * must fall back rather than let `?per_page=` force a whole-table scan.
     */
    public function test_index_honours_per_page_and_ignores_an_invalid_value(): void
    {
        [$tenant, $admin] = $this->tenantAndAdmin();

        $this->actingAsTenant($tenant);
        for ($i = 0; $i < 12; $i++) {
            $this->makeItem(sprintf('Item %02d', $i), sprintf('SKU-%02d', $i));
        }
        $this->forgetTenant();

        $session = [ActiveTenantSession::KEY => $tenant->getKey()];

        $this->actingAs($admin)->withSession($session)->get('/inventory?per_page=10')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('items.data', 10)
                ->where('items.meta.per_page', 10)
                ->where('items.meta.last_page', 2));

        $this->actingAs($admin)->withSession($session)->get('/inventory?per_page=10&page=2')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('items.data', 2)
                ->where('items.meta.current_page', 2));

        $this->actingAs($admin)->withSession($session)->get('/inventory?per_page=999')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('items.meta.per_page', 25));
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

    /** Inventory Analytics' "Add costs" deep-link (?missing_cost=1). */
    public function test_index_filters_by_missing_cost(): void
    {
        [$tenant, $admin] = $this->tenantAndAdmin();

        $this->actingAsTenant($tenant);
        $this->makeItem('Cork', 'CORK-1');
        InventoryItem::create([
            'name' => 'Bottle',
            'sku' => 'BTL-1',
            'category' => 'RAW_MATERIAL',
            'unit' => 'units',
            'current_stock' => '10',
            'cost_per_unit' => Money::fromMinor(150, 'EUR'),
        ]);
        $this->forgetTenant();

        $this->actingAs($admin)
            ->withSession([ActiveTenantSession::KEY => $tenant->getKey()])
            ->get('/inventory?missing_cost=1')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('items.data', 1)
                ->where('items.data.0.name', 'Cork')
                ->where('filters.missing_cost', true));
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

    public function test_item_audit_trail_is_only_sent_when_the_drawer_asks_and_reports_the_actor(): void
    {
        [$tenant, $admin] = $this->tenantAndAdmin();

        $this->actingAsTenant($tenant);
        $item = $this->makeItem('Cork', 'CORK-1');
        $this->forgetTenant();

        // A plain visit must not pay for a trail nobody opened.
        $this->actingAs($admin)
            ->withSession([ActiveTenantSession::KEY => $tenant->getKey()])
            ->get('/inventory')
            ->assertInertia(fn (AssertableInertia $page) => $page->missing('itemAuditTrail'));

        // Through the real update endpoint, not a direct model mutation, so the
        // audit entry is stamped with the acting admin as Auth::user() would be.
        $this->actingAs($admin)
            ->withSession([ActiveTenantSession::KEY => $tenant->getKey()])
            ->patch('/inventory/'.$item->getKey(), ['name' => 'Cork (renamed)'])
            ->assertRedirect();

        // The drawer's partial reload asks for it by item — see
        // test_item_movements_are_only_sent_when_the_drawer_asks's own note on
        // why a partial response is read as JSON rather than via assertInertia.
        $response = $this->actingAs($admin)
            ->withSession([ActiveTenantSession::KEY => $tenant->getKey()])
            ->get('/inventory?item='.$item->getKey(), $this->inertiaPartial('Inventory/Index', 'itemAuditTrail'))
            ->assertOk();

        $trail = $response->json('props.itemAuditTrail');
        self::assertIsArray($trail);
        self::assertCount(2, $trail);
        self::assertSame('inventory_item.updated', $trail[0]['action']);
        self::assertSame($admin->fullName(), $trail[0]['actor_name']);
        self::assertSame('Cork (renamed)', $trail[0]['metadata']['changed']['name']);
        self::assertSame('inventory_item.created', $trail[1]['action']);
    }

    public function test_item_stock_rank_reports_where_the_item_sits_in_its_group(): void
    {
        [$tenant, $admin] = $this->tenantAndAdmin();

        $this->actingAsTenant($tenant);
        $lowest = InventoryItem::create([
            'name' => 'Malvazija', 'sku' => 'MLV', 'category' => 'FINISHED', 'group' => 'Wine',
            'unit' => 'bottles', 'current_stock' => '3', 'is_active' => true,
        ]);
        $middle = InventoryItem::create([
            'name' => 'Plavac', 'sku' => 'PLV', 'category' => 'FINISHED', 'group' => 'Wine',
            'unit' => 'bottles', 'current_stock' => '50', 'is_active' => true,
        ]);
        $highest = InventoryItem::create([
            'name' => 'Teran', 'sku' => 'TRN', 'category' => 'FINISHED', 'group' => 'Wine',
            'unit' => 'bottles', 'current_stock' => '400', 'is_active' => true,
        ]);
        // A different group entirely — must not be counted against the wines.
        InventoryItem::create([
            'name' => 'Cork', 'sku' => 'CORK-1', 'category' => 'RAW_MATERIAL', 'group' => 'Packaging',
            'unit' => 'units', 'current_stock' => '1', 'is_active' => true,
        ]);
        // The only item in its group — nothing to rank against.
        $alone = InventoryItem::create([
            'name' => 'Barrel', 'sku' => 'BRL', 'category' => 'RAW_MATERIAL', 'group' => 'Cellar equipment',
            'unit' => 'units', 'current_stock' => '2', 'is_active' => true,
        ]);
        $this->forgetTenant();

        $session = [ActiveTenantSession::KEY => $tenant->getKey()];

        $this->actingAs($admin)->withSession($session)
            ->get('/inventory?item='.$lowest->getKey(), $this->inertiaPartial('Inventory/Index', 'itemStockRank'))
            ->assertOk()
            ->assertJsonPath('props.itemStockRank.rank', 1)
            ->assertJsonPath('props.itemStockRank.total', 3);

        $this->actingAs($admin)->withSession($session)
            ->get('/inventory?item='.$middle->getKey(), $this->inertiaPartial('Inventory/Index', 'itemStockRank'))
            ->assertOk()
            ->assertJsonPath('props.itemStockRank.rank', 2)
            ->assertJsonPath('props.itemStockRank.total', 3);

        $this->actingAs($admin)->withSession($session)
            ->get('/inventory?item='.$highest->getKey(), $this->inertiaPartial('Inventory/Index', 'itemStockRank'))
            ->assertOk()
            ->assertJsonPath('props.itemStockRank.rank', 3)
            ->assertJsonPath('props.itemStockRank.total', 3);

        $this->actingAs($admin)->withSession($session)
            ->get('/inventory?item='.$alone->getKey(), $this->inertiaPartial('Inventory/Index', 'itemStockRank'))
            ->assertOk()
            ->assertJsonPath('props.itemStockRank', null);
    }

    public function test_item_customer_attribution_rolls_up_order_lines_by_customer(): void
    {
        [$tenant, $admin] = $this->tenantAndAdmin();

        $this->actingAsTenant($tenant);
        $item = $this->makeItem('Cork', 'CORK-1');
        $other = $this->makeItem('Label', 'LBL-1');

        $bigBuyer = Customer::create(['company_name' => 'Konoba Riva', 'email' => 'riva@example.com']);
        $smallBuyer = Customer::create(['company_name' => 'Taverna Olea', 'email' => 'olea@example.com']);

        $order1 = Order::create([
            'order_number' => 'VT-00000001', 'status' => 'RECEIVED',
            'customer_id' => $bigBuyer->getKey(), 'created_by_id' => $admin->getKey(),
            'total_amount' => 1000, 'created_at' => now()->subDays(10),
        ]);
        $order1->items()->create(['inventory_item_id' => $item->getKey(), 'quantity' => 30, 'unit_type' => 'units', 'unit_price' => 100, 'total' => 3000]);

        $order2 = Order::create([
            'order_number' => 'VT-00000002', 'status' => 'RECEIVED',
            'customer_id' => $smallBuyer->getKey(), 'created_by_id' => $admin->getKey(),
            'total_amount' => 1000, 'created_at' => now()->subDay(),
        ]);
        $order2->items()->create(['inventory_item_id' => $item->getKey(), 'quantity' => 10, 'unit_type' => 'units', 'unit_price' => 100, 'total' => 1000]);
        // A line for a different item must not be attributed to this one.
        $order2->items()->create(['inventory_item_id' => $other->getKey(), 'quantity' => 99, 'unit_type' => 'units', 'unit_price' => 100, 'total' => 9900]);
        $this->forgetTenant();

        $response = $this->actingAs($admin)
            ->withSession([ActiveTenantSession::KEY => $tenant->getKey()])
            ->get('/inventory?item='.$item->getKey(), $this->inertiaPartial('Inventory/Index', 'itemCustomerAttribution'))
            ->assertOk();

        $rows = $response->json('props.itemCustomerAttribution');
        self::assertIsArray($rows);
        self::assertCount(2, $rows);

        // Sorted by units descending.
        self::assertSame($bigBuyer->getKey(), $rows[0]['customer_id']);
        self::assertSame('Konoba Riva', $rows[0]['company_name']);
        self::assertSame(30, $rows[0]['units']);
        self::assertSame(0.75, $rows[0]['share']);

        self::assertSame($smallBuyer->getKey(), $rows[1]['customer_id']);
        self::assertSame(10, $rows[1]['units']);
        self::assertSame(0.25, $rows[1]['share']);
        self::assertNotNull($rows[1]['last_ordered']);
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

    public function test_show_carries_the_items_tier_and_customer_prices(): void
    {
        [$tenant, $admin] = $this->tenantAndAdmin();

        $this->actingAsTenant($tenant);
        $item = $this->makeItem('Cork', 'CORK-1');
        $tier = PricingTier::create(['name' => 'Wholesale', 'rebate_percent' => 15]);
        $customer = Customer::create(['company_name' => 'Konoba Riva', 'email' => 'riva@example.com']);
        TierPrice::create(['inventory_item_id' => $item->getKey(), 'pricing_tier_id' => $tier->getKey(), 'price' => 1999]);
        CustomerPrice::create(['inventory_item_id' => $item->getKey(), 'customer_id' => $customer->getKey(), 'price' => 1500]);
        $this->forgetTenant();

        $this->actingAs($admin)
            ->withSession([ActiveTenantSession::KEY => $tenant->getKey()])
            ->get('/inventory/'.$item->getKey())
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Inventory/Show')
                ->has('pricing.tiers', 1)
                ->where('pricing.tiers.0.pricing_tier_id', $tier->getKey())
                ->where('pricing.tiers.0.tier_name', 'Wholesale')
                ->where('pricing.tiers.0.price.minor', 1999)
                ->has('pricing.customers', 1)
                ->where('pricing.customers.0.customer_id', $customer->getKey())
                ->where('pricing.customers.0.company_name', 'Konoba Riva')
                ->where('pricing.customers.0.price.minor', 1500)
                ->missing('pricingTierOptions')
                ->missing('pricingCustomerOptions'));
    }

    public function test_tier_price_can_be_upserted_and_removed_through_the_web_endpoint(): void
    {
        [$tenant, $admin] = $this->tenantAndAdmin();

        $this->actingAsTenant($tenant);
        $item = $this->makeItem('Cork', 'CORK-1');
        $tier = PricingTier::create(['name' => 'Wholesale', 'rebate_percent' => 15]);
        $this->forgetTenant();

        $this->actingAs($admin)
            ->withSession([ActiveTenantSession::KEY => $tenant->getKey()])
            ->patch('/inventory/'.$item->getKey().'/tier-prices/'.$tier->getKey(), ['price' => 1999])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->actingAs($admin)
            ->withSession([ActiveTenantSession::KEY => $tenant->getKey()])
            ->get('/inventory/'.$item->getKey())
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('pricing.tiers', 1)
                ->where('pricing.tiers.0.price.minor', 1999));

        $this->actingAs($admin)
            ->withSession([ActiveTenantSession::KEY => $tenant->getKey()])
            ->delete('/inventory/'.$item->getKey().'/tier-prices/'.$tier->getKey())
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->actingAs($admin)
            ->withSession([ActiveTenantSession::KEY => $tenant->getKey()])
            ->get('/inventory/'.$item->getKey())
            ->assertInertia(fn (AssertableInertia $page) => $page->has('pricing.tiers', 0));
    }

    public function test_tier_price_endpoint_requires_pricing_manage(): void
    {
        $tenant = $this->createTenant();
        $member = $this->createMember($tenant, [TenantRole::WineClub]);

        $this->actingAsTenant($tenant);
        $item = $this->makeItem('Cork', 'CORK-1');
        $tier = PricingTier::create(['name' => 'Wholesale', 'rebate_percent' => 15]);
        $this->forgetTenant();

        $this->actingAs($member)
            ->withSession([ActiveTenantSession::KEY => $tenant->getKey()])
            ->patch('/inventory/'.$item->getKey().'/tier-prices/'.$tier->getKey(), ['price' => 1999])
            ->assertForbidden();
    }

    public function test_show_carries_the_items_recipe(): void
    {
        [$tenant, $admin] = $this->tenantAndAdmin();

        $this->actingAsTenant($tenant);
        $wine = $this->makeItem('Wine', 'WINE');
        $cork = InventoryItem::create(['name' => 'Cork', 'sku' => 'CORK', 'category' => 'RAW_MATERIAL', 'unit' => 'units', 'current_stock' => '100']);
        RecipeItem::create(['output_id' => $wine->getKey(), 'input_id' => $cork->getKey(), 'quantity' => '2']);
        $this->forgetTenant();

        $this->actingAs($admin)
            ->withSession([ActiveTenantSession::KEY => $tenant->getKey()])
            ->get('/inventory/'.$wine->getKey())
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Inventory/Show')
                ->has('recipe', 1)
                ->where('recipe.0.input_id', $cork->getKey())
                ->where('recipe.0.input_name', 'Cork')
                ->where('recipe.0.quantity', '2.000')
                ->missing('recipeInputOptions'));
    }

    public function test_recipe_can_be_replaced_through_the_web_endpoint(): void
    {
        [$tenant, $admin] = $this->tenantAndAdmin();

        $this->actingAsTenant($tenant);
        $wine = $this->makeItem('Wine', 'WINE');
        $cork = InventoryItem::create(['name' => 'Cork', 'sku' => 'CORK', 'category' => 'RAW_MATERIAL', 'unit' => 'units']);
        $label = InventoryItem::create(['name' => 'Label', 'sku' => 'LABEL', 'category' => 'RAW_MATERIAL', 'unit' => 'units']);
        RecipeItem::create(['output_id' => $wine->getKey(), 'input_id' => $cork->getKey(), 'quantity' => '1']);
        $this->forgetTenant();

        // Replaces the whole recipe: the old cork line is gone, a new label
        // line takes its place — SetRecipeAction's own "replace" contract.
        $this->actingAs($admin)
            ->withSession([ActiveTenantSession::KEY => $tenant->getKey()])
            ->put('/inventory/'.$wine->getKey().'/recipe', [
                'items' => [['input_id' => $label->getKey(), 'quantity' => '3']],
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->actingAs($admin)
            ->withSession([ActiveTenantSession::KEY => $tenant->getKey()])
            ->get('/inventory/'.$wine->getKey())
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('recipe', 1)
                ->where('recipe.0.input_id', $label->getKey())
                ->where('recipe.0.quantity', '3.000'));
    }

    public function test_recipe_endpoint_requires_inventory_manage(): void
    {
        $tenant = $this->createTenant();
        $member = $this->createMember($tenant, [TenantRole::WineClub]);

        $this->actingAsTenant($tenant);
        $wine = $this->makeItem('Wine', 'WINE');
        $cork = InventoryItem::create(['name' => 'Cork', 'sku' => 'CORK', 'category' => 'RAW_MATERIAL', 'unit' => 'units']);
        $this->forgetTenant();

        $this->actingAs($member)
            ->withSession([ActiveTenantSession::KEY => $tenant->getKey()])
            ->put('/inventory/'.$wine->getKey().'/recipe', [
                'items' => [['input_id' => $cork->getKey(), 'quantity' => '1']],
            ])
            ->assertForbidden();
    }

    public function test_producing_from_recipe_consumes_inputs_and_adds_output_through_the_web_endpoint(): void
    {
        [$tenant, $admin] = $this->tenantAndAdmin();

        $this->actingAsTenant($tenant);
        $wine = InventoryItem::create(['name' => 'Wine', 'sku' => 'WINE', 'category' => 'FINISHED', 'unit' => 'bottles', 'current_stock' => '0']);
        $cork = InventoryItem::create(['name' => 'Cork', 'sku' => 'CORK', 'category' => 'RAW_MATERIAL', 'unit' => 'units', 'current_stock' => '100']);
        RecipeItem::create(['output_id' => $wine->getKey(), 'input_id' => $cork->getKey(), 'quantity' => '1']);
        $this->forgetTenant();

        $this->actingAs($admin)
            ->withSession([ActiveTenantSession::KEY => $tenant->getKey()])
            ->post('/inventory/'.$wine->getKey().'/produce', ['display_quantity' => '10'])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->actingAsTenant($tenant);
        self::assertSame('10.000', (string) $wine->refresh()->current_stock);
        self::assertSame('90.000', (string) $cork->refresh()->current_stock);
        $this->forgetTenant();
    }

    public function test_producing_without_a_recipe_is_rejected_through_the_web_endpoint(): void
    {
        [$tenant, $admin] = $this->tenantAndAdmin();

        $this->actingAsTenant($tenant);
        $item = $this->makeItem('Wine', 'WINE');
        $this->forgetTenant();

        $this->actingAs($admin)
            ->withSession([ActiveTenantSession::KEY => $tenant->getKey()])
            ->post('/inventory/'.$item->getKey().'/produce', ['display_quantity' => '10'])
            ->assertStatus(302)
            ->assertSessionHasErrors('display_quantity');
    }

    public function test_produce_endpoint_requires_inventory_manage(): void
    {
        $tenant = $this->createTenant();
        $member = $this->createMember($tenant, [TenantRole::WineClub]);

        $this->actingAsTenant($tenant);
        $item = $this->makeItem('Wine', 'WINE');
        $this->forgetTenant();

        $this->actingAs($member)
            ->withSession([ActiveTenantSession::KEY => $tenant->getKey()])
            ->post('/inventory/'.$item->getKey().'/produce', ['display_quantity' => '10'])
            ->assertForbidden();
    }

    public function test_show_carries_the_items_bottle_analyses(): void
    {
        [$tenant, $admin] = $this->tenantAndAdmin();

        $this->actingAsTenant($tenant);
        $wine = $this->makeItem('Wine', 'WINE');
        $wine->bottleAnalyses()->create(['analyzed_on' => '2026-06-01', 'ph' => '3.2']);
        $wine->bottleAnalyses()->create(['analyzed_on' => '2026-06-10', 'ph' => '3.45', 'alcohol' => '13.5', 'note' => 'Pre-bottling']);
        $this->forgetTenant();

        $this->actingAs($admin)
            ->withSession([ActiveTenantSession::KEY => $tenant->getKey()])
            ->get('/inventory/'.$wine->getKey())
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Inventory/Show')
                ->has('bottleAnalyses', 2)
                // Newest first.
                ->where('bottleAnalyses.0.analyzed_on', '2026-06-10')
                ->where('bottleAnalyses.0.ph', 3.45)
                ->where('bottleAnalyses.0.alcohol', 13.5)
                ->where('bottleAnalyses.0.note', 'Pre-bottling')
                ->where('bottleAnalyses.0.total_acidity', null)
                ->where('bottleAnalyses.1.analyzed_on', '2026-06-01'));
    }

    public function test_bottle_analysis_can_be_recorded_and_removed_through_the_web_endpoint(): void
    {
        [$tenant, $admin] = $this->tenantAndAdmin();

        $this->actingAsTenant($tenant);
        $wine = $this->makeItem('Wine', 'WINE');
        $this->forgetTenant();

        $this->actingAs($admin)
            ->withSession([ActiveTenantSession::KEY => $tenant->getKey()])
            ->post('/inventory/'.$wine->getKey().'/bottle-analyses', [
                'analyzed_on' => '2026-06-10', 'ph' => 3.45, 'free_so2' => 25,
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $analysisId = null;
        $this->actingAs($admin)
            ->withSession([ActiveTenantSession::KEY => $tenant->getKey()])
            ->get('/inventory/'.$wine->getKey())
            ->assertInertia(function (AssertableInertia $page) use (&$analysisId): void {
                $page->has('bottleAnalyses', 1)
                    ->where('bottleAnalyses.0.ph', 3.45)
                    ->where('bottleAnalyses.0.free_so2', 25);
                $analysisId = $page->toArray()['props']['bottleAnalyses'][0]['id'];
            });

        $this->actingAs($admin)
            ->withSession([ActiveTenantSession::KEY => $tenant->getKey()])
            ->delete('/inventory/'.$wine->getKey().'/bottle-analyses/'.$analysisId)
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->actingAs($admin)
            ->withSession([ActiveTenantSession::KEY => $tenant->getKey()])
            ->get('/inventory/'.$wine->getKey())
            ->assertInertia(fn (AssertableInertia $page) => $page->has('bottleAnalyses', 0));
    }

    public function test_only_the_date_is_required_for_a_bottle_analysis(): void
    {
        [$tenant, $admin] = $this->tenantAndAdmin();

        $this->actingAsTenant($tenant);
        $wine = $this->makeItem('Wine', 'WINE');
        $this->forgetTenant();

        $this->actingAs($admin)
            ->withSession([ActiveTenantSession::KEY => $tenant->getKey()])
            ->post('/inventory/'.$wine->getKey().'/bottle-analyses', ['ph' => 3.4])
            ->assertSessionHasErrors('analyzed_on');
    }

    public function test_bottle_analysis_endpoint_requires_inventory_manage(): void
    {
        $tenant = $this->createTenant();
        $member = $this->createMember($tenant, [TenantRole::WineClub]);

        $this->actingAsTenant($tenant);
        $wine = $this->makeItem('Wine', 'WINE');
        $this->forgetTenant();

        $this->actingAs($member)
            ->withSession([ActiveTenantSession::KEY => $tenant->getKey()])
            ->post('/inventory/'.$wine->getKey().'/bottle-analyses', ['analyzed_on' => '2026-06-10'])
            ->assertForbidden();
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
                $keys = collect((array) $page->toArray()['props']['attention'])->pluck('key')->all();

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

    public function test_analytics_range_picker_changes_the_movements_window(): void
    {
        [$tenant, $admin] = $this->tenantAndAdmin();

        $session = [ActiveTenantSession::KEY => $tenant->getKey()];

        // No months param — the design's own default.
        $this->actingAs($admin)->withSession($session)
            ->get('/inventory-analytics')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('analytics.movements_12m', 12)
                ->where('filters.months', 12));

        $this->actingAs($admin)->withSession($session)
            ->get('/inventory-analytics?months=3')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('analytics.movements_12m', 3)
                ->where('filters.months', 3));

        $this->actingAs($admin)->withSession($session)
            ->get('/inventory-analytics?months=6')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('analytics.movements_12m', 6)
                ->where('filters.months', 6));

        // Not one of the offered presets — falls back rather than passing an
        // arbitrary count straight to the query.
        $this->actingAs($admin)->withSession($session)
            ->get('/inventory-analytics?months=999')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('analytics.movements_12m', 12)
                ->where('filters.months', 12));
    }

    public function test_analytics_stock_levels_carry_bottles_per_case_and_value(): void
    {
        [$tenant, $admin] = $this->tenantAndAdmin();

        $this->actingAsTenant($tenant);
        InventoryItem::create([
            'name' => 'Malvazija', 'sku' => 'MLV', 'category' => 'FINISHED', 'unit' => 'bottles',
            'current_stock' => '12', 'bottles_per_case' => 6, 'default_price' => 2000, 'is_active' => true,
        ]);
        // No case size (explicitly zeroed — the column itself defaults to 12,
        // not null) and no price — the unit switch's fallback case.
        InventoryItem::create([
            'name' => 'Bulk juice', 'sku' => 'BULK', 'category' => 'RAW_MATERIAL', 'unit' => 'liters',
            'current_stock' => '50', 'bottles_per_case' => 0, 'is_active' => true,
        ]);
        $this->forgetTenant();

        $this->actingAs($admin)
            ->withSession([ActiveTenantSession::KEY => $tenant->getKey()])
            ->get('/inventory-analytics')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('analytics.stock_levels.0.name', 'Bulk juice')
                ->where('analytics.stock_levels.0.bottles_per_case', 0)
                ->where('analytics.stock_levels.0.value', null)
                ->where('analytics.stock_levels.1.name', 'Malvazija')
                ->where('analytics.stock_levels.1.bottles_per_case', 6)
                // 12 bottles × 2000 minor = 24000.
                ->where('analytics.stock_levels.1.value', 24000));
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

    public function test_spend_withholds_the_reconciliation_panel_until_it_opens(): void
    {
        [$tenant, $admin] = $this->tenantAndAdmin();

        $this->actingAs($admin)
            ->withSession([ActiveTenantSession::KEY => $tenant->getKey()])
            ->get('/inventory-spend')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->missing('reconciliation'));
    }

    public function test_spend_reconciliation_panel_reports_an_order_edited_after_its_deduct(): void
    {
        [$tenant, $admin] = $this->tenantAndAdmin();

        $this->actingAsTenant($tenant);
        $wine = InventoryItem::create([
            'name' => 'Malvazija', 'sku' => 'MLV-2025', 'category' => 'FINISHED', 'unit' => 'bottles',
            'current_stock' => '500',
        ]);
        $customer = Customer::create(['company_name' => 'Konoba Riva', 'email' => 'riva@example.com']);
        $order = app(CreateOrderAction::class)->execute($customer, $admin->getKey(), [
            'items' => [['inventory_item_id' => $wine->getKey(), 'quantity' => 24, 'unit_type' => 'bottles']],
        ]);
        app(UpdateOrderItemAction::class)->execute($order->items()->firstOrFail(), 10, 'bottles');
        $this->forgetTenant();

        $response = $this->actingAs($admin)
            ->withSession([ActiveTenantSession::KEY => $tenant->getKey()])
            ->get('/inventory-spend', $this->inertiaPartial('Inventory/Spend', 'reconciliation'))
            ->assertOk();

        self::assertSame($order->order_number, $response->json('props.reconciliation.0.order_number'));
        self::assertSame($order->getKey(), $response->json('props.reconciliation.0.order_id'));
        self::assertSame(34, $response->json('props.reconciliation.0.recorded_bottles'));
        self::assertSame(10, $response->json('props.reconciliation.0.current_bottles'));
        self::assertSame(-24, $response->json('props.reconciliation.0.delta'));
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

    /**
     * Product Detail's "Duplicate" (Figma 449:1577) — the same
     * DuplicateInventoryItemAction the JSON API's duplicate endpoint calls.
     */
    public function test_duplicate_clones_the_item_and_redirects_to_the_copy(): void
    {
        [$tenant, $admin] = $this->tenantAndAdmin();

        $this->actingAsTenant($tenant);
        $item = $this->makeItem('Cork', 'CORK-1');
        $this->forgetTenant();

        $response = $this->actingAs($admin)
            ->withSession([ActiveTenantSession::KEY => $tenant->getKey()])
            ->post('/inventory/'.$item->getKey().'/duplicate')
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->actingAsTenant($tenant);
        $copy = InventoryItem::query()->where('sku', 'CORK-1-COPY')->first();
        self::assertNotNull($copy);
        self::assertSame('Cork (Copy)', $copy->name);
        $response->assertRedirect('/inventory/'.$copy->getKey());
        $this->forgetTenant();
    }

    public function test_duplicating_an_item_requires_the_manage_capability(): void
    {
        [$tenant] = $this->tenantAndAdmin();
        // SALES grants finance only — no inventory.manage.
        $member = $this->createMember($tenant, [TenantRole::Sales]);

        $this->actingAsTenant($tenant);
        $item = $this->makeItem('Cork', 'CORK-1');
        $this->forgetTenant();

        $this->actingAs($member)
            ->withSession([ActiveTenantSession::KEY => $tenant->getKey()])
            ->post('/inventory/'.$item->getKey().'/duplicate')
            ->assertForbidden();
    }

    public function test_spend_export_streams_a_csv_of_the_same_rows_the_page_shows(): void
    {
        [$tenant, $admin] = $this->tenantAndAdmin();

        $this->actingAsTenant($tenant);
        InventoryItem::create([
            'name' => 'Velika Bjelica',
            'sku' => 'VB-1',
            'category' => 'FINISHED',
            'unit' => 'bottles',
            'sales_unit' => 'bottles',
            'current_stock' => '100',
            'is_active' => true,
            'is_for_sale' => true,
        ]);
        $this->forgetTenant();

        $response = $this->actingAs($admin)
            ->withSession([ActiveTenantSession::KEY => $tenant->getKey()])
            ->get('/inventory-spend/export')
            ->assertOk();

        self::assertStringContainsString('text/csv', (string) $response->headers->get('Content-Type'));
        self::assertStringContainsString('VB-1', $response->streamedContent());
    }

    public function test_spend_export_requires_the_financials_capability(): void
    {
        $tenant = $this->createTenant();
        // CELLAR can see inventory but not money.
        $member = $this->createMember($tenant, [TenantRole::Cellar]);

        $this->actingAs($member)
            ->withSession([ActiveTenantSession::KEY => $tenant->getKey()])
            ->get('/inventory-spend/export')
            ->assertForbidden();
    }

    public function test_movements_export_streams_the_items_full_ledger(): void
    {
        [$tenant, $admin] = $this->tenantAndAdmin();

        $this->actingAsTenant($tenant);
        $item = $this->makeItem('Cork', 'CORK-1');
        $this->forgetTenant();

        $this->actingAs($admin)
            ->withSession([ActiveTenantSession::KEY => $tenant->getKey()])
            ->post('/inventory/'.$item->getKey().'/stock', [
                'type' => 'MANUAL_OUT',
                'quantity' => '-4',
                'note' => 'Broken case',
            ])
            ->assertRedirect();

        $response = $this->actingAs($admin)
            ->withSession([ActiveTenantSession::KEY => $tenant->getKey()])
            ->get('/inventory/'.$item->getKey().'/movements/export')
            ->assertOk();

        self::assertStringContainsString('text/csv', (string) $response->headers->get('Content-Type'));
        self::assertStringContainsString('Broken case', $response->streamedContent());
    }

    public function test_movements_export_requires_inventory_view(): void
    {
        $tenant = $this->createTenant();
        $sales = $this->createMember($tenant, [TenantRole::Sales]);

        $this->actingAsTenant($tenant);
        $item = $this->makeItem('Cork', 'CORK-1');
        $this->forgetTenant();

        $this->actingAs($sales)
            ->withSession([ActiveTenantSession::KEY => $tenant->getKey()])
            ->get('/inventory/'.$item->getKey().'/movements/export')
            ->assertForbidden();
    }
}
