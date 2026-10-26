<?php

declare(strict_types=1);

namespace Tests\Feature\Web;

use App\Actions\Orders\CreateOrderAction;
use App\Actions\Orders\UpdateOrderItemAction;
use App\Enums\OrderStatus;
use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Enums\TenantRole;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\InventoryImage;
use App\Models\InventoryItem;
use App\Models\Order;
use App\Models\PricingTier;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Vessel;
use App\Models\WineLot;
use App\Models\WorkOrder;
use App\Services\Auth\ActiveTenantSession;
use App\Services\Cellar\LotVolumeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Inertia\Inertia;
use Inertia\Testing\AssertableInertia;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\InteractsWithTenancy;
use Tests\TestCase;

/**
 * Server-side behaviour behind the QA round: what the Dashboard, Customer page, Create order drawer,
 * Work Orders board and Inventory list now rely on.
 */
class WebQaFixesTest extends TestCase
{
    use InteractsWithTenancy;
    use RefreshDatabase;

    /** @return array{0: Tenant, 1: User} */
    private function tenantAndAdmin(): array
    {
        $tenant = $this->createTenant();

        return [$tenant, $this->createMember($tenant, [TenantRole::Admin])];
    }

    /** @return array<string, string> */
    private function tenantSession(Tenant $tenant): array
    {
        return [ActiveTenantSession::KEY => $tenant->getKey()];
    }

    private function customer(string $company = 'Restoran Mediteran'): Customer
    {
        return Customer::create(['company_name' => $company, 'email' => str($company)->slug().'@example.test', 'customer_type' => 'RETAIL']);
    }

    private function order(Customer $customer, User $by, int $minor, \DateTimeInterface $at, bool $consignment = false): Order
    {
        $order = Order::create([
            'order_number' => 'QA-'.uniqid(),
            'status' => OrderStatus::Shipped->value,
            'total_amount' => $minor,
            'customer_id' => $customer->getKey(),
            'created_by_id' => $by->getKey(),
            'is_consignment' => $consignment,
        ]);
        $order->forceFill(['created_at' => $at])->save();

        return $order;
    }

    /* ---- Dashboard ---------------------------------------------------- */

    public function test_dashboard_revenue_follows_the_selected_period_not_year_to_date(): void
    {
        [$tenant, $admin] = $this->tenantAndAdmin();

        $this->actingAsTenant($tenant);
        $customer = $this->customer();
        $this->order($customer, $admin, 10000, now());                    // today
        $this->order($customer, $admin, 50000, now()->subDays(40));       // this year, not this week
        $this->forgetTenant();

        $week = $this->actingAs($admin)->withSession($this->tenantSession($tenant))->get('/dashboard?period=week');
        $week->assertInertia(fn (AssertableInertia $page) => $page
            ->where('summary.stats.revenue', 10000)
            ->has('summary.revenue')
            ->has('summary.revenue_by_channel.total.previous'));

        $ytd = $this->actingAs($admin)->withSession($this->tenantSession($tenant))->get('/dashboard?period=ytd');
        $ytd->assertInertia(fn (AssertableInertia $page) => $page->where('summary.stats.revenue', 60000));
    }

    public function test_dashboard_tasks_can_be_ticked_off_through_the_status_endpoint(): void
    {
        [$tenant, $admin] = $this->tenantAndAdmin();

        $this->actingAsTenant($tenant);
        $task = WorkOrder::create([
            'title' => 'Order corks',
            'status' => TaskStatus::Todo,
            'priority' => TaskPriority::Medium,
            'created_by_id' => $admin->getKey(),
            'due_date' => now()->addDay(),
        ]);
        $this->forgetTenant();

        $this->actingAs($admin)->withSession($this->tenantSession($tenant))
            ->get('/dashboard')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('summary.upcoming_tasks.rows.0.id', $task->getKey()));

        $this->actingAs($admin)->withSession($this->tenantSession($tenant))
            ->patch("/work-orders/{$task->getKey()}/status", ['status' => 'DONE'])
            ->assertRedirect();

        $this->actingAs($admin)->withSession($this->tenantSession($tenant))
            ->get('/dashboard')
            ->assertInertia(fn (AssertableInertia $page) => $page->has('summary.upcoming_tasks.rows', 0));
    }

    /* ---- Customers ---------------------------------------------------- */

    public function test_creating_a_customer_from_another_drawer_stays_on_the_page(): void
    {
        [$tenant, $admin] = $this->tenantAndAdmin();

        $this->actingAs($admin)->withSession($this->tenantSession($tenant))
            ->from('/orders')
            ->post('/customers', ['company_name' => 'Nova Vinoteka', 'email' => 'nova@example.test', 'stay' => true])
            ->assertRedirect('/orders')
            ->assertSessionHas('success');

        $this->actingAsTenant($tenant);
        $this->assertSame(1, Customer::query()->where('company_name', 'Nova Vinoteka')->count());
    }

    public function test_the_consignment_tab_only_shows_for_customers_with_consignment_orders(): void
    {
        [$tenant, $admin] = $this->tenantAndAdmin();

        $this->actingAsTenant($tenant);
        $plain = $this->customer('Plain Customer');
        $komisija = $this->customer('Komisija Customer');
        $this->order($plain, $admin, 1000, now());
        $this->order($komisija, $admin, 1000, now(), consignment: true);
        $this->forgetTenant();

        $this->actingAs($admin)->withSession($this->tenantSession($tenant))
            ->get('/customers/'.$plain->getKey())
            ->assertInertia(fn (AssertableInertia $page) => $page->where('hasConsignment', false));

        $this->actingAs($admin)->withSession($this->tenantSession($tenant))
            ->get('/customers/'.$komisija->getKey())
            ->assertInertia(fn (AssertableInertia $page) => $page->where('hasConsignment', true));
    }

    public function test_products_bought_rows_carry_the_wines_image(): void
    {
        [$tenant, $admin] = $this->tenantAndAdmin();

        $this->actingAsTenant($tenant);
        $customer = $this->customer();
        $wine = InventoryItem::create([
            'name' => 'Malvazija', 'sku' => 'MAL-1', 'category' => 'FINISHED', 'unit' => 'bottles',
            'group' => 'Wine', 'subcategory' => 'White', 'current_stock' => '10', 'default_price' => 1500,
        ]);
        InventoryImage::create([
            'inventory_item_id' => $wine->getKey(),
            'object_key' => 'tenants/'.$tenant->getKey().'/inventory/mal.jpg',
            'content_type' => 'image/jpeg',
            'size_bytes' => 10,
            'sort_order' => 0,
        ]);
        $order = $this->order($customer, $admin, 3000, now());
        $order->items()->create([
            'inventory_item_id' => $wine->getKey(), 'quantity' => 2, 'unit_type' => 'bottles',
            'unit_price' => 1500, 'total' => 3000,
        ]);
        $this->forgetTenant();

        $this->actingAs($admin)->withSession($this->tenantSession($tenant))
            ->get('/customers/'.$customer->getKey())
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('products.rows.0.name', 'Malvazija')
                ->where('products.rows.0.group', 'Wine')
                ->where('products.rows.0.image_url', fn ($url) => is_string($url) && str_contains($url, 'mal.jpg')));
    }

    /* ---- Inventory drag and drop ------------------------------------- */

    private function item(string $name, string $group, ?string $sub = null, int $sort = 0): InventoryItem
    {
        return InventoryItem::create([
            'name' => $name, 'sku' => strtoupper($name), 'category' => 'FINISHED', 'unit' => 'bottles',
            'group' => $group, 'subcategory' => $sub, 'current_stock' => '5', 'sort_order' => $sort,
        ]);
    }

    /** @return list<string> */
    private function listed(User $admin, Tenant $tenant): array
    {
        $names = [];
        $this->actingAs($admin)->withSession($this->tenantSession($tenant))->get('/inventory')
            ->assertInertia(function (AssertableInertia $page) use (&$names) {
                $names = array_column($page->toArray()['props']['items']['data'], 'name');
            });

        return $names;
    }

    public function test_items_can_be_rearranged_within_a_band(): void
    {
        [$tenant, $admin] = $this->tenantAndAdmin();

        $this->actingAsTenant($tenant);
        $a = $this->item('Alpha', 'Wine', 'Red', 0);
        $b = $this->item('Bravo', 'Wine', 'Red', 1);
        $c = $this->item('Charlie', 'Wine', 'Red', 2);
        $this->forgetTenant();

        $this->assertSame(['Alpha', 'Bravo', 'Charlie'], $this->listed($admin, $tenant));

        $this->actingAs($admin)->withSession($this->tenantSession($tenant))
            ->post('/inventory-order', ['ids' => [$c->getKey(), $a->getKey(), $b->getKey()]])
            ->assertRedirect();

        $this->assertSame(['Charlie', 'Alpha', 'Bravo'], $this->listed($admin, $tenant));
    }

    public function test_rearranging_in_the_background_answers_without_a_redirect(): void
    {
        [$tenant, $admin] = $this->tenantAndAdmin();

        $this->actingAsTenant($tenant);
        $a = $this->item('Alpha', 'Wine', 'Red', 0);
        $b = $this->item('Bravo', 'Wine', 'Red', 1);
        $this->forgetTenant();

        $this->actingAs($admin)->withSession($this->tenantSession($tenant))
            ->postJson('/inventory-order', ['ids' => [$b->getKey(), $a->getKey()]])
            ->assertNoContent();

        $this->assertSame(['Bravo', 'Alpha'], $this->listed($admin, $tenant));
    }

    public function test_groups_and_subcategories_can_be_rearranged(): void
    {
        [$tenant, $admin] = $this->tenantAndAdmin();

        $this->actingAsTenant($tenant);
        $this->item('Pinot', 'Wine', 'Red');
        $this->item('Malvazija', 'Wine', 'White');
        $this->item('Cork', 'Closures');
        $this->forgetTenant();

        // Unarranged: categories in the order they were created (a new one goes last).
        $this->assertSame(['Pinot', 'Malvazija', 'Cork'], $this->listed($admin, $tenant));

        $this->actingAs($admin)->withSession($this->tenantSession($tenant))
            ->post('/inventory-order', ['category' => 'FINISHED', 'entries' => [['group' => 'Closures'], ['group' => 'Wine']]])
            ->assertRedirect();
        $this->assertSame(['Cork', 'Pinot', 'Malvazija'], $this->listed($admin, $tenant));

        $this->actingAs($admin)->withSession($this->tenantSession($tenant))
            ->post('/inventory-order', ['category' => 'FINISHED', 'entries' => [
                ['group' => 'Wine', 'subcategory' => 'White'],
                ['group' => 'Wine', 'subcategory' => 'Red'],
            ]])
            ->assertRedirect();
        $this->assertSame(['Cork', 'Malvazija', 'Pinot'], $this->listed($admin, $tenant));
    }

    public function test_only_members_who_can_manage_inventory_may_rearrange(): void
    {
        $tenant = $this->createTenant();
        $cellar = $this->createMember($tenant, [TenantRole::Cellar]);

        $this->actingAs($cellar)->withSession($this->tenantSession($tenant))
            ->post('/inventory-order', ['ids' => ['x']])
            ->assertForbidden();
    }

    public function test_rearranging_validates_its_payload(): void
    {
        [$tenant, $admin] = $this->tenantAndAdmin();

        $this->actingAs($admin)->withSession($this->tenantSession($tenant))
            ->post('/inventory-order', [])
            ->assertSessionHasErrors(['ids', 'entries']);
    }

    /* ---- Create order: unit switching -------------------------------- */

    public function test_an_existing_catalog_line_can_be_switched_from_bottles_to_cases_and_reprices(): void
    {
        [$tenant, $admin] = $this->tenantAndAdmin();

        $this->actingAsTenant($tenant);
        $customer = $this->customer();
        $wine = InventoryItem::create([
            'name' => 'Teran', 'sku' => 'TER-1', 'category' => 'FINISHED', 'unit' => 'bottles', 'sales_unit' => 'bottles',
            'bottles_per_case' => 6, 'current_stock' => '100', 'default_price' => 1000, 'is_for_sale' => true,
        ]);
        $order = app(CreateOrderAction::class)->execute($customer, $admin->getKey(), [
            'items' => [['inventory_item_id' => $wine->getKey(), 'quantity' => 2, 'unit_type' => 'bottles']],
        ]);
        $line = $order->items()->firstOrFail();

        app(UpdateOrderItemAction::class)->execute($line, null, 'cases');

        $line->refresh();
        $this->assertSame('cases', $line->unit_type);
        $this->assertSame(6000, $line->unit_price->getMinorAmount());
        $this->assertSame(12000, $line->total->getMinorAmount());
    }

    /* ---- Work orders: Recent Activity -------------------------------- */

    public function test_recent_activity_lists_tasks_added_or_moved_in_the_last_hour_only(): void
    {
        [$tenant, $admin] = $this->tenantAndAdmin();

        $this->actingAsTenant($tenant);
        $make = fn (string $title, \DateTimeInterface $at): WorkOrder => tap(WorkOrder::create([
            'title' => $title, 'status' => TaskStatus::Todo, 'priority' => TaskPriority::Medium, 'created_by_id' => $admin->getKey(),
        ]), fn (WorkOrder $t) => $t->forceFill(['created_at' => $at])->save());

        $fresh = $make('Fresh task', now()->subMinutes(10));
        $old = $make('Old task', now()->subHours(5));
        $movedRecently = $make('Moved task', now()->subHours(5));
        $movedLongAgo = $make('Moved long ago', now()->subHours(5));
        $this->forgetTenant();

        // One real move through the endpoint, one backdated beyond the hour.
        $this->actingAs($admin)->withSession($this->tenantSession($tenant))
            ->patch("/work-orders/{$movedRecently->getKey()}/status", ['status' => 'IN_PROGRESS']);
        $this->actingAs($admin)->withSession($this->tenantSession($tenant))
            ->patch("/work-orders/{$movedLongAgo->getKey()}/status", ['status' => 'DONE']);
        AuditLog::query()
            ->where('subject_id', $movedLongAgo->getKey())
            ->update(['created_at' => now()->subHours(3)]);

        $titles = [];
        $this->actingAs($admin)->withSession($this->tenantSession($tenant))
            ->get('/work-orders')
            ->assertInertia(fn (AssertableInertia $page) => $page->missing('recentActivity'));

        $this->actingAs($admin)->withSession($this->tenantSession($tenant))
            ->get('/work-orders', ['X-Inertia' => 'true', 'X-Inertia-Version' => Inertia::getVersion(), 'X-Inertia-Partial-Component' => 'WorkOrders/Index', 'X-Inertia-Partial-Data' => 'recentActivity'])
            ->assertOk()
            ->assertJsonCount(2, 'props.recentActivity');

        /** @var list<array<string, mixed>> $rows */
        $rows = $this->actingAs($admin)->withSession($this->tenantSession($tenant))
            ->get('/work-orders', ['X-Inertia' => 'true', 'X-Inertia-Version' => Inertia::getVersion(), 'X-Inertia-Partial-Component' => 'WorkOrders/Index', 'X-Inertia-Partial-Data' => 'recentActivity'])
            ->json('props.recentActivity');

        $byTitle = collect($rows)->keyBy('task.title');
        $this->assertSame('added', $byTitle['Fresh task']['kind']);
        $this->assertSame('moved', $byTitle['Moved task']['kind']);
        $this->assertSame('TODO', $byTitle['Moved task']['from']);
        $this->assertSame('IN_PROGRESS', $byTitle['Moved task']['to']);
        $this->assertFalse($byTitle->has('Old task'));
        $this->assertFalse($byTitle->has('Moved long ago'));
    }

    /* ---- Customer look-up (OIB / EU VAT via VIES) --------------------- */

    public function test_lookup_fills_name_and_address_for_a_croatian_oib(): void
    {
        Http::fake(['ec.europa.eu/*' => Http::response([
            'isValid' => true, 'name' => 'NEVER GREEN D.O.O.', 'address' => 'SUĆIDAR 1, SPLIT, 21000 Split',
        ])]);
        [$tenant, $admin] = $this->tenantAndAdmin();

        $this->actingAs($admin)->withSession($this->tenantSession($tenant))
            ->getJson('/customers/lookup-vat?vat=39580006726')
            ->assertOk()
            ->assertJsonPath('data.vat', 'HR39580006726')
            ->assertJsonPath('data.name', 'NEVER GREEN D.O.O.')
            ->assertJsonPath('data.address', 'SUĆIDAR 1')
            ->assertJsonPath('data.zip', '21000')
            ->assertJsonPath('data.city', 'Split')
            ->assertJsonPath('data.country', 'HR')
            ->assertJsonPath('data.details_available', true);
    }

    public function test_lookup_rejects_a_mistyped_oib_without_calling_vies(): void
    {
        Http::fake();
        [$tenant, $admin] = $this->tenantAndAdmin();

        $this->actingAs($admin)->withSession($this->tenantSession($tenant))
            ->getJson('/customers/lookup-vat?vat=39580006727')
            ->assertStatus(422);

        Http::assertNothingSent();
    }

    public function test_lookup_works_for_other_eu_countries_and_says_when_details_are_withheld(): void
    {
        Http::fake(['ec.europa.eu/*' => Http::response([
            'isValid' => true, 'name' => '---', 'address' => '---',
        ])]);
        [$tenant, $admin] = $this->tenantAndAdmin();

        $this->actingAs($admin)->withSession($this->tenantSession($tenant))
            ->getJson('/customers/lookup-vat?vat=DE811128135')
            ->assertOk()
            ->assertJsonPath('data.country', 'DE')
            ->assertJsonPath('data.name', null)
            ->assertJsonPath('data.details_available', false);

        Http::assertSent(fn ($request) => str_contains($request->url(), '/DE/vat/811128135'));
    }

    public function test_lookup_distinguishes_unregistered_from_service_unavailable(): void
    {
        [$tenant, $admin] = $this->tenantAndAdmin();

        Http::fake(['ec.europa.eu/*' => Http::sequence()
            ->push(['isValid' => false, 'userError' => 'INVALID'])
            ->push(['isValid' => false, 'userError' => 'MS_UNAVAILABLE'])]);
        $unregistered = $this->actingAs($admin)->withSession($this->tenantSession($tenant))
            ->getJson('/customers/lookup-vat?vat=39580006726')->assertStatus(422)->json('message');

        $unavailable = $this->actingAs($admin)->withSession($this->tenantSession($tenant))
            ->getJson('/customers/lookup-vat?vat=39580006726')->assertStatus(422)->json('message');

        $this->assertNotSame($unregistered, $unavailable);
        $this->assertStringContainsString('zauzeta', $unavailable);
    }

    public function test_only_members_who_can_create_customers_may_look_up(): void
    {
        $tenant = $this->createTenant();
        $cellar = $this->createMember($tenant, [TenantRole::Cellar]);

        $this->actingAs($cellar)->withSession($this->tenantSession($tenant))
            ->getJson('/customers/lookup-vat?vat=39580006726')
            ->assertForbidden();
    }

    /* ---- Pricing tiers management ------------------------------------ */

    public function test_pricing_tiers_can_be_listed_added_renamed_and_deleted(): void
    {
        [$tenant, $admin] = $this->tenantAndAdmin();
        $session = $this->tenantSession($tenant);

        $this->actingAs($admin)->withSession($session)
            ->post('/customers/pricing-tiers', ['name' => 'Distributor', 'rebate_percent' => 29, 'description' => 'Importers'])
            ->assertRedirect();

        $this->actingAsTenant($tenant);
        $tier = PricingTier::query()->where('name', 'Distributor')->firstOrFail();
        $customer = $this->customer();
        $customer->forceFill(['pricing_tier_id' => $tier->getKey()])->save();
        $this->forgetTenant();

        $this->actingAs($admin)->withSession($session)->get('/customers/pricing-tiers')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Customers/PricingTiers')
                ->where('tiers.0.name', 'Distributor')
                ->where('tiers.0.customers_count', 1)
                ->where('tiers.0.prices_count', 0)
                ->where('tiers.0.rebate_percent', '29.00'));

        // Saving a tier under its own name must not trip the uniqueness rule.
        $this->actingAs($admin)->withSession($session)
            ->patch("/customers/pricing-tiers/{$tier->getKey()}", ['name' => 'Distributor', 'rebate_percent' => 31])
            ->assertSessionHasNoErrors();

        $this->actingAs($admin)->withSession($session)
            ->post('/customers/pricing-tiers', ['name' => 'Distributor'])
            ->assertSessionHasErrors('name');

        $this->actingAs($admin)->withSession($session)
            ->delete("/customers/pricing-tiers/{$tier->getKey()}")
            ->assertRedirect();

        $this->actingAsTenant($tenant);
        $this->assertSame(0, PricingTier::query()->count());
        $this->assertNull($customer->refresh()->pricing_tier_id);
    }

    public function test_viewers_can_see_pricing_tiers_but_not_change_them(): void
    {
        $tenant = $this->createTenant();
        $team = $this->createMember($tenant, [TenantRole::Orders]);
        $session = $this->tenantSession($tenant);

        $this->actingAs($team)->withSession($session)->post('/customers/pricing-tiers', ['name' => 'X'])->assertForbidden();
    }

    /* ---- Inventory: chips and totals are server-side ------------------ */

    public function test_attention_chips_filter_the_whole_list_on_the_server_not_just_the_page(): void
    {
        [$tenant, $admin] = $this->tenantAndAdmin();

        $this->actingAsTenant($tenant);
        // 30 items (more than one page of 25): the 4 with no cost are spread across the whole list.
        for ($i = 0; $i < 30; $i++) {
            InventoryItem::create([
                'name' => sprintf('Item %02d', $i), 'sku' => sprintf('SKU-%02d', $i), 'category' => 'FINISHED', 'unit' => 'bottles',
                'group' => 'Wine', 'current_stock' => '5', 'min_stock' => '1',
                'cost_per_unit' => in_array($i, [0, 9, 17, 29], true) ? null : 500,
            ]);
        }
        $this->forgetTenant();

        $this->actingAs($admin)->withSession($this->tenantSession($tenant))
            ->get('/inventory?attention=no_cost_per_unit')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('filters.attention', 'no_cost_per_unit')
                ->where('items.meta.total', 4)
                ->has('items.data', 4)
                // The chip's own count agrees with what it filters to.
                ->where('attention.0.key', 'no_cost_per_unit')
                ->where('attention.0.count', 4));

        // An unknown chip key is ignored rather than trusted.
        $this->actingAs($admin)->withSession($this->tenantSession($tenant))
            ->get('/inventory?attention=drop_table')
            ->assertInertia(fn (AssertableInertia $page) => $page->where('filters.attention', null)->where('items.meta.total', 30));
    }

    public function test_the_list_header_totals_cover_every_matching_item(): void
    {
        [$tenant, $admin] = $this->tenantAndAdmin();

        $this->actingAsTenant($tenant);
        for ($i = 0; $i < 30; $i++) {
            InventoryItem::create(['name' => "Item {$i}", 'sku' => "S{$i}", 'category' => 'FINISHED', 'unit' => 'bottles', 'current_stock' => '10']);
        }
        $this->forgetTenant();

        $this->actingAs($admin)->withSession($this->tenantSession($tenant))
            ->get('/inventory?per_page=25')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('items.data', 25)
                ->where('items.meta.total', 30)
                ->where('totals.on_hand', fn ($onHand) => (float) $onHand === 300.0));
    }

    public function test_search_runs_over_the_whole_catalogue_not_the_current_page(): void
    {
        [$tenant, $admin] = $this->tenantAndAdmin();

        $this->actingAsTenant($tenant);
        for ($i = 0; $i < 30; $i++) {
            InventoryItem::create(['name' => sprintf('Wine %02d', $i), 'sku' => sprintf('W-%02d', $i), 'category' => 'FINISHED', 'unit' => 'bottles', 'current_stock' => '1']);
        }
        // Sorts last, so it is on page 2 of the unfiltered list.
        InventoryItem::create(['name' => 'Zzz Needle', 'sku' => 'NEEDLE', 'category' => 'FINISHED', 'unit' => 'bottles', 'current_stock' => '1']);
        $this->forgetTenant();

        $this->actingAs($admin)->withSession($this->tenantSession($tenant))
            ->get('/inventory?search=needle')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('items.data', 1)
                ->where('items.data.0.sku', 'NEEDLE'));
    }

    /* ---- Cellar: new wine lot form ------------------------------------ */

    public function test_a_new_lot_can_be_created_with_its_stage_and_wine_type(): void
    {
        [$tenant, $admin] = $this->tenantAndAdmin();

        $this->actingAs($admin)->withSession($this->tenantSession($tenant))
            ->post('/cellar/lots', [
                'name' => 'Plavac Mali Reserve 2025', 'grape_variety' => 'Plavac Mali', 'vintage' => '2025',
                'wine_type' => 'RED', 'status' => 'AGING', 'initial_volume' => 450,
            ])
            ->assertRedirect();

        $this->actingAsTenant($tenant);
        $lot = WineLot::query()->where('name', 'Plavac Mali Reserve 2025')->firstOrFail();
        $this->assertSame('AGING', $lot->status->value);
        $this->assertSame('RED', $lot->wine_type?->value);
    }

    public function test_a_new_lot_goes_into_a_part_full_vessel_when_it_fits_and_is_refused_when_it_does_not(): void
    {
        [$tenant, $admin] = $this->tenantAndAdmin();

        $this->actingAsTenant($tenant);
        $barrel = Vessel::create(['name' => 'B1', 'type' => 'BARREL', 'capacity_liters' => '225.000']);
        $existing = WineLot::create(['lot_number' => 'L-1', 'name' => 'Already here', 'grape_variety' => 'Syrah', 'vintage' => '2024', 'wine_type' => 'RED', 'status' => 'AGING', 'initial_volume' => '150.000', 'current_volume' => '150.000']);
        app(LotVolumeService::class)->assignToVessel($existing, $barrel, '150.000');
        $this->forgetTenant();

        $lot = fn (int $litres) => [
            'name' => "New {$litres}", 'grape_variety' => 'Merlot', 'vintage' => '2025', 'wine_type' => 'RED',
            'initial_volume' => $litres, 'vessel_id' => $barrel->getKey(),
        ];

        // 100 L cannot join 150 L in a 225 L barrel: refused, with the error on the vessel field, nothing saved.
        $this->actingAs($admin)->withSession($this->tenantSession($tenant))
            ->post('/cellar/lots', $lot(100))
            ->assertSessionHasErrors('vessel_id');
        $this->actingAsTenant($tenant);
        $this->assertSame(0, WineLot::query()->where('name', 'New 100')->count());
        $this->forgetTenant();

        // 75 L fits exactly; the barrel then holds both lots and is full.
        $this->actingAs($admin)->withSession($this->tenantSession($tenant))
            ->post('/cellar/lots', $lot(75))
            ->assertSessionHasNoErrors();
        $this->actingAsTenant($tenant);
        $this->assertSame(2, $barrel->vesselLots()->count());
        $this->assertSame('225.000', (string) $barrel->refresh()->current_volume);
    }

    public function test_the_dashboard_screen_skips_blocks_it_does_not_draw_but_the_api_keeps_them(): void
    {
        [$tenant, $admin] = $this->tenantAndAdmin();

        $this->actingAs($admin)->withSession($this->tenantSession($tenant))->get('/dashboard?period=ytd')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('summary.revenue_summary', null)
                ->where('summary.recent_orders', null)
                ->has('summary.stats')
                ->has('summary.revenue_by_channel'));

        Sanctum::actingAs($admin);
        $this->getJson('/api/v1/dashboard?period=ytd', $this->tenantHeader($tenant))->assertOk()
            ->assertJsonStructure(['data' => ['revenue_summary', 'revenue_trend', 'top_products', 'recent_orders']]);
    }
}
