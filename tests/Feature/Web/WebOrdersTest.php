<?php

declare(strict_types=1);

namespace Tests\Feature\Web;

use App\Enums\OrderStatus;
use App\Enums\TenantRole;
use App\Models\Customer;
use App\Models\InventoryItem;
use App\Models\Order;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Auth\ActiveTenantSession;
use App\Support\Money\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Inertia;
use Inertia\Testing\AssertableInertia;
use Tests\Concerns\InteractsWithTenancy;
use Tests\TestCase;

/**
 * The Inertia orders page and its writes.
 *
 * Reads go through the same ListOrdersQuery + OrderPresenter as the JSON API
 * and writes through the same Actions, so these tests are about the page
 * envelope, the capability gates and the rules the web layer adds on top —
 * the shipped-visibility scope and the drawer's partial reload — rather than a
 * second copy of the ordering rules.
 */
class WebOrdersTest extends TestCase
{
    use InteractsWithTenancy;
    use RefreshDatabase;

    /** @return array{0: Tenant, 1: User} */
    private function tenantAndAdmin(): array
    {
        $tenant = $this->createTenant();

        return [$tenant, $this->createMember($tenant, [TenantRole::Admin])];
    }

    private function makeCustomer(string $company = 'Restoran Mediteran'): Customer
    {
        return Customer::create([
            'company_name' => $company,
            'contact_name' => 'Marija Vukoja',
            'email' => 'orders@'.str($company)->slug().'.test',
            'city' => 'Zadar',
            'customer_type' => 'RETAIL',
            'rebate_percent' => '18',
            'is_active' => true,
        ]);
    }

    private function makeProduct(string $name = 'Velika Bjelica'): InventoryItem
    {
        return InventoryItem::create([
            'name' => $name,
            'sku' => str($name)->slug()->upper()->value(),
            'category' => 'FINISHED',
            'unit' => 'bottles',
            'sales_unit' => 'bottles',
            'current_stock' => '500',
            'is_for_sale' => true,
            'default_price' => Money::fromMinor(2548, 'EUR'),
        ]);
    }

    private function makeOrder(Customer $customer, User $author, OrderStatus $status = OrderStatus::Received): Order
    {
        $order = Order::create([
            'order_number' => 'VT-'.fake()->unique()->numerify('########'),
            'status' => $status,
            'customer_id' => $customer->getKey(),
            'created_by_id' => $author->getKey(),
            'total_amount' => Money::fromMinor(337058, 'EUR'),
        ]);

        $order->statusHistories()->create([
            'status' => $status,
            'note' => 'Order created',
            'changed_by_id' => $author->getKey(),
        ]);

        return $order;
    }

    public function test_index_renders_orders_with_pagination_status_counts_and_pipeline(): void
    {
        [$tenant, $admin] = $this->tenantAndAdmin();

        $this->actingAsTenant($tenant);
        $customer = $this->makeCustomer();
        $this->makeOrder($customer, $admin, OrderStatus::Received);
        $this->makeOrder($customer, $admin, OrderStatus::Shipped);
        $this->forgetTenant();

        $this->actingAs($admin)
            ->withSession([ActiveTenantSession::KEY => $tenant->getKey()])
            ->get('/orders')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Orders/Index')
                ->has('orders.data', 2)
                ->where('orders.meta.total', 2)
                ->where('statusCounts.total', 2)
                ->has('statusCounts.statuses', 4)
                // Six stages: the four order statuses plus Invoiced and Paid.
                ->has('pipeline.stages', 6)
                ->where('filters.status', null));
    }

    public function test_index_filters_by_status_but_chip_counts_still_show_every_status(): void
    {
        [$tenant, $admin] = $this->tenantAndAdmin();

        $this->actingAsTenant($tenant);
        $customer = $this->makeCustomer();
        $this->makeOrder($customer, $admin, OrderStatus::Received);
        $this->makeOrder($customer, $admin, OrderStatus::InProcess);
        $this->forgetTenant();

        $this->actingAs($admin)
            ->withSession([ActiveTenantSession::KEY => $tenant->getKey()])
            ->get('/orders?status=RECEIVED')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('orders.data', 1)
                ->where('orders.data.0.status', 'RECEIVED')
                ->where('filters.status', 'RECEIVED')
                // The chips must not collapse to the filtered set, or you could
                // not see what you were switching to.
                ->where('statusCounts.total', 2));
    }

    public function test_index_filters_by_search_on_order_number_and_customer(): void
    {
        [$tenant, $admin] = $this->tenantAndAdmin();

        $this->actingAsTenant($tenant);
        $this->makeOrder($this->makeCustomer('Taverna Olea'), $admin);
        $this->makeOrder($this->makeCustomer('Konoba Kraljevac'), $admin);
        $this->forgetTenant();

        $this->actingAs($admin)
            ->withSession([ActiveTenantSession::KEY => $tenant->getKey()])
            ->get('/orders?search=Taverna')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('orders.data', 1)
                ->where('orders.data.0.customer.company_name', 'Taverna Olea'));
    }

    public function test_period_filter_narrows_the_table_and_the_pipeline(): void
    {
        [$tenant, $admin] = $this->tenantAndAdmin();

        $this->actingAsTenant($tenant);
        $customer = $this->makeCustomer();
        $this->makeOrder($customer, $admin);
        $old = $this->makeOrder($customer, $admin);
        $old->forceFill(['created_at' => now()->subYears(2)])->saveQuietly();
        $this->forgetTenant();

        $this->actingAs($admin)
            ->withSession([ActiveTenantSession::KEY => $tenant->getKey()])
            ->get('/orders?period=today')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('orders.data', 1)
                ->where('filters.period', 'today'));
    }

    public function test_a_member_without_shipped_visibility_never_sees_shipped_orders(): void
    {
        $tenant = $this->createTenant();
        $member = $this->createMember($tenant, [TenantRole::Orders]);

        $this->actingAsTenant($tenant);
        $customer = $this->makeCustomer();
        $this->makeOrder($customer, $member, OrderStatus::Received);
        $this->makeOrder($customer, $member, OrderStatus::Shipped);
        $this->forgetTenant();

        $this->actingAs($member)
            ->withSession([ActiveTenantSession::KEY => $tenant->getKey()])
            ->get('/orders')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('orders.data', 1)
                ->where('orders.data.0.status', 'RECEIVED')
                ->where('statusCounts.total', 1));
    }

    public function test_the_shipped_scope_cannot_be_bypassed_from_the_query_string(): void
    {
        $tenant = $this->createTenant();
        $member = $this->createMember($tenant, [TenantRole::Orders]);

        $this->actingAsTenant($tenant);
        $this->makeOrder($this->makeCustomer(), $member, OrderStatus::Shipped);
        $this->forgetTenant();

        $this->actingAs($member)
            ->withSession([ActiveTenantSession::KEY => $tenant->getKey()])
            ->get('/orders?status=SHIPPED')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->has('orders.data', 0));
    }

    public function test_the_page_is_closed_to_a_member_without_orders_view(): void
    {
        $tenant = $this->createTenant();
        $cellar = $this->createMember($tenant, [TenantRole::Cellar]);

        $this->actingAs($cellar)
            ->withSession([ActiveTenantSession::KEY => $tenant->getKey()])
            ->get('/orders')
            ->assertForbidden();
    }

    public function test_the_drawer_asks_for_one_order_and_gets_its_full_detail(): void
    {
        [$tenant, $admin] = $this->tenantAndAdmin();

        $this->actingAsTenant($tenant);
        $customer = $this->makeCustomer();
        $order = $this->makeOrder($customer, $admin);
        $this->forgetTenant();

        // `order` is an optional prop: absent from the first render, present on
        // the partial reload the drawer performs.
        $this->actingAs($admin)
            ->withSession([ActiveTenantSession::KEY => $tenant->getKey()])
            ->get('/orders')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->missing('order'));

        $response = $this->actingAs($admin)
            ->withSession([ActiveTenantSession::KEY => $tenant->getKey()])
            ->get('/orders?order='.$order->getKey(), [
                'X-Inertia' => 'true',
                'X-Inertia-Version' => Inertia::getVersion(),
                'X-Inertia-Partial-Component' => 'Orders/Index',
                'X-Inertia-Partial-Data' => 'order',
            ]);

        $response->assertOk();
        $this->assertSame($order->getKey(), $response->json('props.order.id'));
        $this->assertSame('Restoran Mediteran', $response->json('props.order.customer.company_name'));
        $this->assertIsArray($response->json('props.order.status_history'));
    }

    public function test_store_creates_an_order_and_redirects_to_its_drawer(): void
    {
        [$tenant, $admin] = $this->tenantAndAdmin();

        $this->actingAsTenant($tenant);
        $customer = $this->makeCustomer();
        $product = $this->makeProduct();
        $this->forgetTenant();

        $response = $this->actingAs($admin)
            ->withSession([ActiveTenantSession::KEY => $tenant->getKey()])
            ->post('/orders', [
                'customer_id' => $customer->getKey(),
                'items' => [
                    ['inventory_item_id' => $product->getKey(), 'quantity' => 6],
                ],
            ]);

        $this->actingAsTenant($tenant);
        $order = Order::query()->firstOrFail();
        $this->assertSame(1, $order->items()->count());
        $this->forgetTenant();

        $response->assertRedirect('/orders?order='.$order->getKey());
        $response->assertSessionHas('success');
    }

    public function test_store_rejects_a_custom_line_without_a_description_or_price(): void
    {
        [$tenant, $admin] = $this->tenantAndAdmin();

        $this->actingAsTenant($tenant);
        $customer = $this->makeCustomer();
        $this->forgetTenant();

        $this->actingAs($admin)
            ->withSession([ActiveTenantSession::KEY => $tenant->getKey()])
            ->post('/orders', [
                'customer_id' => $customer->getKey(),
                'items' => [['quantity' => 2]],
            ])
            ->assertSessionHasErrors(['items.0.custom_description', 'items.0.unit_price']);
    }

    public function test_the_stepper_advances_status_and_records_history(): void
    {
        [$tenant, $admin] = $this->tenantAndAdmin();

        $this->actingAsTenant($tenant);
        $order = $this->makeOrder($this->makeCustomer(), $admin, OrderStatus::Received);
        $this->forgetTenant();

        $this->actingAs($admin)
            ->withSession([ActiveTenantSession::KEY => $tenant->getKey()])
            ->patch('/orders/'.$order->getKey().'/status', ['status' => 'IN_PROCESS'])
            ->assertRedirect();

        $this->actingAsTenant($tenant);
        $this->assertSame(OrderStatus::InProcess, $order->refresh()->status);
        $this->assertSame(2, $order->statusHistories()->count());
        $this->forgetTenant();
    }

    public function test_any_order_viewer_may_comment(): void
    {
        $tenant = $this->createTenant();
        $member = $this->createMember($tenant, [TenantRole::Orders]);

        $this->actingAsTenant($tenant);
        $order = $this->makeOrder($this->makeCustomer(), $member);
        $this->forgetTenant();

        $this->actingAs($member)
            ->withSession([ActiveTenantSession::KEY => $tenant->getKey()])
            ->post('/orders/'.$order->getKey().'/comments', ['content' => 'Kupac traži dostavu prije vikenda.'])
            ->assertRedirect();

        $this->actingAsTenant($tenant);
        $this->assertSame(1, $order->orderNotes()->count());
        $this->forgetTenant();
    }

    public function test_deleting_an_order_is_admin_only(): void
    {
        $tenant = $this->createTenant();
        $admin = $this->createMember($tenant, [TenantRole::Admin]);
        $orderRole = $this->createMember($tenant, [TenantRole::Orders]);

        $this->actingAsTenant($tenant);
        $order = $this->makeOrder($this->makeCustomer(), $admin);
        $this->forgetTenant();

        // orders.delete is not in the ORDERS role's grants.
        $this->actingAs($orderRole)
            ->withSession([ActiveTenantSession::KEY => $tenant->getKey()])
            ->delete('/orders/'.$order->getKey())
            ->assertForbidden();

        $this->actingAs($admin)
            ->withSession([ActiveTenantSession::KEY => $tenant->getKey()])
            ->delete('/orders/'.$order->getKey())
            ->assertRedirect('/orders');
    }

    public function test_writes_are_closed_to_a_viewer_without_orders_manage(): void
    {
        $tenant = $this->createTenant();
        $sales = $this->createMember($tenant, [TenantRole::Sales]);

        $this->actingAs($sales)
            ->withSession([ActiveTenantSession::KEY => $tenant->getKey()])
            ->post('/orders', ['customer_id' => 'x', 'items' => []])
            ->assertForbidden();
    }
}
