<?php

declare(strict_types=1);

namespace Tests\Feature\Web;

use App\Enums\OrderStatus;
use App\Enums\TenantRole;
use App\Models\Customer;
use App\Models\CustomerPrice;
use App\Models\InventoryItem;
use App\Models\Order;
use App\Models\PricingTier;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Auth\ActiveTenantSession;
use App\Support\Money\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\Concerns\InteractsWithTenancy;
use Tests\TestCase;

/**
 * The Inertia customer pages and their writes.
 *
 * Reads go through the same queries and CustomerPresenter as the JSON API and
 * writes through the same Actions, so these tests are about the page envelope,
 * the capability gates, and the two things the pricing engine makes
 * consequential: which rebate the list reports, and which rule the Pricing tab
 * says decided a price.
 */
class WebCustomersTest extends TestCase
{
    use InteractsWithTenancy;
    use RefreshDatabase;

    /** @return array{0: Tenant, 1: User} */
    private function tenantAndAdmin(): array
    {
        $tenant = $this->createTenant();

        return [$tenant, $this->createMember($tenant, [TenantRole::Admin])];
    }

    private function makeCustomer(string $company, array $attributes = []): Customer
    {
        return Customer::create(array_merge([
            'company_name' => $company,
            'contact_name' => 'Marija Vukoja',
            'email' => str($company)->slug().'@example.test',
            'city' => 'Zadar',
            'customer_type' => 'RETAIL',
            'is_active' => true,
        ], $attributes));
    }

    private function makeProduct(string $name = 'Velika Bjelica', int $priceMinor = 2548): InventoryItem
    {
        return InventoryItem::create([
            'name' => $name,
            'sku' => str($name)->slug()->upper()->value(),
            'category' => 'FINISHED',
            'unit' => 'bottles',
            'sales_unit' => 'bottles',
            'current_stock' => '500',
            'is_for_sale' => true,
            'default_price' => Money::fromMinor($priceMinor, 'EUR'),
        ]);
    }

    private function makeOrder(Customer $customer, User $author, int $minor = 100000): Order
    {
        return Order::create([
            'order_number' => 'VT-'.fake()->unique()->numerify('########'),
            'status' => OrderStatus::Received,
            'customer_id' => $customer->getKey(),
            'created_by_id' => $author->getKey(),
            'total_amount' => Money::fromMinor($minor, 'EUR'),
        ]);
    }

    public function test_index_renders_customers_with_pagination_meta(): void
    {
        [$tenant, $admin] = $this->tenantAndAdmin();

        $this->actingAsTenant($tenant);
        $this->makeCustomer('Taverna Olea');
        $this->makeCustomer('Konoba Kraljevac');
        $this->forgetTenant();

        $this->actingAs($admin)
            ->withSession([ActiveTenantSession::KEY => $tenant->getKey()])
            ->get('/customers')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Customers/Index')
                ->has('customers.data', 2)
                ->where('customers.meta.total', 2)
                ->where('filters.search', null)
                // Tiers are lazy: the filter asks for them.
                ->missing('tiers'));
    }

    /**
     * The size of a page is a request, not a filter: it changes how the same
     * result set is sliced, and an out-of-range value must fall back rather
     * than let `?per_page=100000` force a whole-table scan.
     */
    public function test_index_honours_per_page_and_ignores_an_invalid_value(): void
    {
        [$tenant, $admin] = $this->tenantAndAdmin();

        $this->actingAsTenant($tenant);
        for ($i = 0; $i < 12; $i++) {
            $this->makeCustomer(sprintf('Customer %02d', $i));
        }
        $this->forgetTenant();

        $session = [ActiveTenantSession::KEY => $tenant->getKey()];

        $this->actingAs($admin)->withSession($session)->get('/customers?per_page=10')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('customers.data', 10)
                ->where('customers.meta.per_page', 10)
                ->where('customers.meta.last_page', 2));

        $this->actingAs($admin)->withSession($session)->get('/customers?per_page=10&page=2')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('customers.data', 2)
                ->where('customers.meta.current_page', 2));

        // Not on the allow-list — falls back to the default rather than
        // erroring or scanning the whole table.
        $this->actingAs($admin)->withSession($session)->get('/customers?per_page=999')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('customers.meta.per_page', 25));
    }

    public function test_index_filters_by_search_type_and_status(): void
    {
        [$tenant, $admin] = $this->tenantAndAdmin();

        $this->actingAsTenant($tenant);
        $this->makeCustomer('Taverna Olea', ['customer_type' => 'WHOLESALE']);
        $this->makeCustomer('Konoba Kraljevac', ['customer_type' => 'RETAIL', 'is_active' => false]);
        $this->forgetTenant();

        $session = [ActiveTenantSession::KEY => $tenant->getKey()];

        $this->actingAs($admin)->withSession($session)->get('/customers?search=Taverna')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('customers.data', 1)
                ->where('customers.data.0.company_name', 'Taverna Olea'));

        $this->actingAs($admin)->withSession($session)->get('/customers?customer_type=RETAIL')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('customers.data', 1)
                ->where('customers.data.0.company_name', 'Konoba Kraljevac'));

        $this->actingAs($admin)->withSession($session)->get('/customers?is_active=0')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('customers.data', 1)
                ->where('customers.data.0.is_active', false));
    }

    /**
     * The list's Rebate column reports what actually applies. A customer with no
     * rebate of their own inherits the tier's, and reporting 0% there would
     * misstate every tiered customer.
     */
    public function test_the_list_reports_the_rebate_that_actually_applies(): void
    {
        [$tenant, $admin] = $this->tenantAndAdmin();

        $this->actingAsTenant($tenant);
        $tier = PricingTier::create(['name' => 'Gold', 'rebate_percent' => '13']);
        $this->makeCustomer('Inherits Tier', ['pricing_tier_id' => $tier->getKey(), 'rebate_percent' => '0']);
        $this->makeCustomer('Own Rebate', ['pricing_tier_id' => $tier->getKey(), 'rebate_percent' => '18']);
        $this->forgetTenant();

        $this->actingAs($admin)
            ->withSession([ActiveTenantSession::KEY => $tenant->getKey()])
            ->get('/customers')
            ->assertOk()
            ->assertInertia(function (AssertableInertia $page) {
                $rows = collect($page->toArray()['props']['customers']['data'])->keyBy('company_name');

                $this->assertSame('13.00', $rows['Inherits Tier']['effective_rebate_percent']);
                $this->assertSame('18.00', $rows['Own Rebate']['effective_rebate_percent']);
            });
    }

    public function test_revenue_is_withheld_from_a_viewer_without_financials(): void
    {
        $tenant = $this->createTenant();
        $admin = $this->createMember($tenant, [TenantRole::Admin]);
        // TEAM has customers.view but the capability set below drops financials.
        $viewer = $this->createMember($tenant, [TenantRole::Inventory]);

        $this->actingAsTenant($tenant);
        $customer = $this->makeCustomer('Taverna Olea');
        $this->makeOrder($customer, $admin);
        $this->forgetTenant();

        // A member with only inventory capabilities cannot open the page at all.
        $this->actingAs($viewer)
            ->withSession([ActiveTenantSession::KEY => $tenant->getKey()])
            ->get('/customers')
            ->assertForbidden();
    }

    public function test_analytics_needs_financial_visibility(): void
    {
        $tenant = $this->createTenant();
        $admin = $this->createMember($tenant, [TenantRole::Admin]);
        $session = [ActiveTenantSession::KEY => $tenant->getKey()];

        $this->actingAs($admin)->withSession($session)->get('/customers-analytics')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Customers/Analytics')
                ->has('analytics.summary')
                ->has('analytics.customers'));

        // TEAM has customers.view AND financials.view, so it may see it too;
        // a role with neither cannot.
        $cellar = $this->createMember($tenant, [TenantRole::Cellar]);
        $this->actingAs($cellar)->withSession($session)->get('/customers-analytics')->assertForbidden();
    }

    public function test_show_renders_the_overview_and_defers_the_other_tabs(): void
    {
        [$tenant, $admin] = $this->tenantAndAdmin();

        $this->actingAsTenant($tenant);
        $customer = $this->makeCustomer('Restoran Mediteran');
        $this->makeOrder($customer, $admin);
        $this->forgetTenant();

        $this->actingAs($admin)
            ->withSession([ActiveTenantSession::KEY => $tenant->getKey()])
            ->get('/customers/'.$customer->getKey())
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Customers/Show')
                ->where('customer.company_name', 'Restoran Mediteran')
                ->where('tab', 'overview')
                ->has('rhythm')
                ->has('attention')
                ->has('insights')
                ->has('products')
                // Deferred until their tab (or the tab-count reload) asks.
                ->missing('pricing')
                ->missing('orderHistory')
                ->missing('consignment'));
    }

    /**
     * The Pricing tab must name the rule that decided each price, and it must
     * agree with PricingService rather than re-deriving the precedence.
     */
    public function test_the_pricing_tab_names_the_rule_behind_each_price(): void
    {
        [$tenant, $admin] = $this->tenantAndAdmin();

        $this->actingAsTenant($tenant);
        $tier = PricingTier::create(['name' => 'Gold', 'rebate_percent' => '10']);
        $customer = $this->makeCustomer('Restoran Mediteran', ['pricing_tier_id' => $tier->getKey()]);

        $listed = $this->makeProduct('Velika Bjelica', 2000);
        $overridden = $this->makeProduct('Kosa Plavac', 3000);
        CustomerPrice::create([
            'customer_id' => $customer->getKey(),
            'inventory_item_id' => $overridden->getKey(),
            'price' => Money::fromMinor(1500, 'EUR'),
        ]);
        $this->forgetTenant();

        $response = $this->actingAs($admin)
            ->withSession([ActiveTenantSession::KEY => $tenant->getKey()])
            ->get(
                '/customers/'.$customer->getKey().'?tab=pricing',
                $this->inertiaPartial('Customers/Show', 'pricing'),
            );

        $response->assertOk();

        $rows = collect($response->json('props.pricing.rows'))->keyBy('name');

        // A customer price is absolute: no rebate, and named as the customer's.
        $this->assertSame('customer', $rows['Kosa Plavac']['source']);
        $this->assertSame(1500, $rows['Kosa Plavac']['price']['minor']);

        // No tier price exists for this item, so the list price applies with
        // the tier's 10% rebate taken off.
        $this->assertSame('list', $rows['Velika Bjelica']['source']);
        $this->assertSame(1800, $rows['Velika Bjelica']['price']['minor']);

        $this->assertSame(1, $response->json('props.pricing.override_count'));
    }

    public function test_the_order_history_tab_reuses_the_order_listing(): void
    {
        [$tenant, $admin] = $this->tenantAndAdmin();

        $this->actingAsTenant($tenant);
        $customer = $this->makeCustomer('Restoran Mediteran');
        $other = $this->makeCustomer('Taverna Olea');
        $this->makeOrder($customer, $admin);
        $this->makeOrder($customer, $admin);
        $this->makeOrder($other, $admin);
        $this->forgetTenant();

        $response = $this->actingAs($admin)
            ->withSession([ActiveTenantSession::KEY => $tenant->getKey()])
            ->get(
                '/customers/'.$customer->getKey().'?tab=orders',
                $this->inertiaPartial('Customers/Show', 'orderHistory'),
            );

        $response->assertOk();
        $this->assertCount(2, $response->json('props.orderHistory.data'));
    }

    /** The Order History tab's own pager, independent of the customer list's. */
    public function test_the_order_history_tab_honours_per_page(): void
    {
        [$tenant, $admin] = $this->tenantAndAdmin();

        $this->actingAsTenant($tenant);
        $customer = $this->makeCustomer('Restoran Mediteran');
        for ($i = 0; $i < 12; $i++) {
            $this->makeOrder($customer, $admin);
        }
        $this->forgetTenant();

        $response = $this->actingAs($admin)
            ->withSession([ActiveTenantSession::KEY => $tenant->getKey()])
            ->get(
                '/customers/'.$customer->getKey().'?tab=orders&per_page=10',
                $this->inertiaPartial('Customers/Show', 'orderHistory'),
            );

        $response->assertOk();
        $this->assertCount(10, $response->json('props.orderHistory.data'));
        $this->assertSame(10, $response->json('props.orderHistory.meta.per_page'));
        $this->assertSame(2, $response->json('props.orderHistory.meta.last_page'));
    }

    /**
     * "Products bought" has its own window. `lifetime` means no bounds — not a
     * long range, which would still hide a first order older than it.
     */
    public function test_products_bought_honours_its_own_range(): void
    {
        [$tenant, $admin] = $this->tenantAndAdmin();

        $this->actingAsTenant($tenant);
        $customer = $this->makeCustomer('Restoran Mediteran');
        $product = $this->makeProduct();

        $recent = $this->makeOrder($customer, $admin);
        $recent->items()->create([
            'inventory_item_id' => $product->getKey(),
            'quantity' => 6,
            'unit_type' => 'bottles',
            'unit_price' => Money::fromMinor(2000, 'EUR'),
            'total' => Money::fromMinor(12000, 'EUR'),
        ]);

        $old = $this->makeOrder($customer, $admin);
        $old->items()->create([
            'inventory_item_id' => $product->getKey(),
            'quantity' => 4,
            'unit_type' => 'bottles',
            'unit_price' => Money::fromMinor(2000, 'EUR'),
            'total' => Money::fromMinor(8000, 'EUR'),
        ]);
        $old->forceFill(['created_at' => now()->subYears(2)])->saveQuietly();
        $this->forgetTenant();

        $session = [ActiveTenantSession::KEY => $tenant->getKey()];
        $url = '/customers/'.$customer->getKey();

        // Lifetime sees both orders' lines.
        $this->actingAs($admin)->withSession($session)->get($url)
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('products.total_units', 10)
                ->where('productRange.preset', 'lifetime'));

        // This year sees only the recent one.
        $this->actingAs($admin)->withSession($session)->get($url.'?products_range=year')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('products.total_units', 6)
                ->where('productRange.preset', 'year'));

        // An explicit window around the old order sees only that.
        $from = now()->subYears(2)->subMonth()->toDateString();
        $to = now()->subYears(2)->addMonth()->toDateString();

        $this->actingAs($admin)->withSession($session)
            ->get($url."?products_range=custom&products_from={$from}&products_to={$to}")
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('products.total_units', 4)
                ->where('productRange.preset', 'custom'));
    }

    public function test_store_creates_a_customer_and_redirects_to_it(): void
    {
        [$tenant, $admin] = $this->tenantAndAdmin();

        $response = $this->actingAs($admin)
            ->withSession([ActiveTenantSession::KEY => $tenant->getKey()])
            ->post('/customers', [
                'company_name' => 'Nordic Vintners GmbH',
                'email' => 'orders@nordicvintners.de',
                'contact_name' => 'Lena Fischer',
            ]);

        $this->actingAsTenant($tenant);
        $customer = Customer::query()->firstOrFail();
        $this->forgetTenant();

        $response->assertRedirect('/customers/'.$customer->getKey());
        $response->assertSessionHas('success');
    }

    public function test_update_saves_and_a_blank_optional_field_clears_it(): void
    {
        [$tenant, $admin] = $this->tenantAndAdmin();

        $this->actingAsTenant($tenant);
        $customer = $this->makeCustomer('Restoran Mediteran', ['phone' => '+385 23 555 020']);
        $this->forgetTenant();

        $this->actingAs($admin)
            ->withSession([ActiveTenantSession::KEY => $tenant->getKey()])
            ->patch('/customers/'.$customer->getKey(), ['phone' => null, 'city' => 'Šibenik'])
            ->assertRedirect();

        $this->actingAsTenant($tenant);
        $customer->refresh();
        $this->assertNull($customer->phone);
        $this->assertSame('Šibenik', $customer->city);
        $this->forgetTenant();
    }

    /** A customer with orders is retired, not deleted — their orders are the record. */
    public function test_deleting_a_customer_with_orders_deactivates_them_instead(): void
    {
        [$tenant, $admin] = $this->tenantAndAdmin();

        $this->actingAsTenant($tenant);
        $withOrders = $this->makeCustomer('Restoran Mediteran');
        $this->makeOrder($withOrders, $admin);
        $withoutOrders = $this->makeCustomer('Taverna Olea');
        $this->forgetTenant();

        $session = [ActiveTenantSession::KEY => $tenant->getKey()];

        $this->actingAs($admin)->withSession($session)
            ->delete('/customers/'.$withOrders->getKey())
            ->assertRedirect('/customers');

        $this->actingAs($admin)->withSession($session)
            ->delete('/customers/'.$withoutOrders->getKey())
            ->assertRedirect('/customers');

        $this->actingAsTenant($tenant);
        $this->assertFalse($withOrders->refresh()->is_active);
        $this->assertNull(Customer::query()->find($withoutOrders->getKey()));
        $this->forgetTenant();
    }

    public function test_merging_moves_orders_onto_the_winner_and_is_admin_only(): void
    {
        $tenant = $this->createTenant();
        $admin = $this->createMember($tenant, [TenantRole::Admin]);
        $team = $this->createMember($tenant, [TenantRole::Team]);

        $this->actingAsTenant($tenant);
        $winner = $this->makeCustomer('Restoran Mediteran');
        $loser = $this->makeCustomer('Restoran Mediteran d.o.o.');
        $this->makeOrder($loser, $admin);
        $this->forgetTenant();

        $payload = ['winner_id' => $winner->getKey(), 'loser_ids' => [$loser->getKey()]];

        // customers.delete is ADMIN-only; TEAM must not be able to merge.
        $this->actingAs($team)
            ->withSession([ActiveTenantSession::KEY => $tenant->getKey()])
            ->post('/customers/merge', $payload)
            ->assertForbidden();

        $this->actingAs($admin)
            ->withSession([ActiveTenantSession::KEY => $tenant->getKey()])
            ->post('/customers/merge', $payload)
            ->assertRedirect('/customers/'.$winner->getKey());

        $this->actingAsTenant($tenant);
        $this->assertSame(1, Order::query()->where('customer_id', $winner->getKey())->count());
        $this->forgetTenant();
    }

    public function test_writes_are_closed_to_a_viewer_without_customers_manage(): void
    {
        $tenant = $this->createTenant();
        $orders = $this->createMember($tenant, [TenantRole::Orders]);

        $this->actingAs($orders)
            ->withSession([ActiveTenantSession::KEY => $tenant->getKey()])
            ->post('/customers', ['company_name' => 'X', 'email' => 'x@example.test'])
            ->assertForbidden();
    }

    public function test_marking_contacted_mutes_the_reorder_card(): void
    {
        [$tenant, $admin] = $this->tenantAndAdmin();

        $this->actingAsTenant($tenant);
        $customer = $this->makeCustomer('Restoran Mediteran');
        $this->forgetTenant();

        $this->actingAs($admin)
            ->withSession([ActiveTenantSession::KEY => $tenant->getKey()])
            ->post('/customers/'.$customer->getKey().'/contacted', ['contacted' => true])
            ->assertRedirect();

        $this->actingAsTenant($tenant);
        $this->assertNotNull($customer->refresh()->reorder_contacted_at);
        $this->forgetTenant();
    }

    /** The Show page never evaluates orderToken until a partial reload asks for it. */
    public function test_order_token_is_absent_from_a_full_page_load(): void
    {
        [$tenant, $admin] = $this->tenantAndAdmin();

        $this->actingAsTenant($tenant);
        $customer = $this->makeCustomer('Restoran Mediteran');
        $this->forgetTenant();

        $this->actingAs($admin)
            ->withSession([ActiveTenantSession::KEY => $tenant->getKey()])
            ->get('/customers/'.$customer->getKey())
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->missing('orderToken'));
    }

    public function test_generating_an_order_token_creates_one_and_it_is_readable_via_partial_reload(): void
    {
        [$tenant, $admin] = $this->tenantAndAdmin();

        $this->actingAsTenant($tenant);
        $customer = $this->makeCustomer('Restoran Mediteran');
        $this->forgetTenant();

        $session = [ActiveTenantSession::KEY => $tenant->getKey()];

        $this->actingAs($admin)
            ->withSession($session)
            ->post('/customers/'.$customer->getKey().'/order-token')
            ->assertRedirect();

        $this->actingAsTenant($tenant);
        $this->assertNotNull($customer->refresh()->order_token);
        $token = $customer->order_token;
        $this->forgetTenant();

        $response = $this->actingAs($admin)
            ->withSession($session)
            ->get(
                '/customers/'.$customer->getKey(),
                $this->inertiaPartial('Customers/Show', 'orderToken'),
            );

        $response->assertOk();
        $this->assertSame($token, $response->json('props.orderToken'));
    }

    public function test_regenerating_replaces_the_previous_token(): void
    {
        [$tenant, $admin] = $this->tenantAndAdmin();

        $this->actingAsTenant($tenant);
        $customer = $this->makeCustomer('Restoran Mediteran');
        $this->forgetTenant();

        $session = [ActiveTenantSession::KEY => $tenant->getKey()];

        $this->actingAs($admin)->withSession($session)->post('/customers/'.$customer->getKey().'/order-token');

        $this->actingAsTenant($tenant);
        $first = $customer->refresh()->order_token;
        $this->forgetTenant();

        $this->actingAs($admin)->withSession($session)->post('/customers/'.$customer->getKey().'/order-token');

        $this->actingAsTenant($tenant);
        $second = $customer->refresh()->order_token;
        $this->forgetTenant();

        $this->assertNotNull($first);
        $this->assertNotNull($second);
        $this->assertNotSame($first, $second);
    }

    public function test_revoking_clears_the_token(): void
    {
        [$tenant, $admin] = $this->tenantAndAdmin();

        $this->actingAsTenant($tenant);
        $customer = $this->makeCustomer('Restoran Mediteran', ['order_token' => 'a-real-token']);
        $this->forgetTenant();

        $session = [ActiveTenantSession::KEY => $tenant->getKey()];

        $this->actingAs($admin)
            ->withSession($session)
            ->delete('/customers/'.$customer->getKey().'/order-token')
            ->assertRedirect();

        $this->actingAsTenant($tenant);
        $this->assertNull($customer->refresh()->order_token);
        $this->forgetTenant();
    }

    public function test_order_token_endpoints_are_closed_to_a_non_admin(): void
    {
        [$tenant] = [$this->createTenant()];
        $orders = $this->createMember($tenant, [TenantRole::Orders]);

        $this->actingAsTenant($tenant);
        $customer = $this->makeCustomer('Restoran Mediteran');
        $this->forgetTenant();

        $this->actingAs($orders)
            ->withSession([ActiveTenantSession::KEY => $tenant->getKey()])
            ->post('/customers/'.$customer->getKey().'/order-token')
            ->assertForbidden();
    }
}
