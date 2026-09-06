<?php

declare(strict_types=1);

namespace Tests\Feature\Web;

use App\Enums\OrderStatus;
use App\Enums\TenantRole;
use App\Models\Customer;
use App\Models\Inflow;
use App\Models\InventoryItem;
use App\Models\Order;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\OrderConfirmationNotification;
use App\Services\Auth\ActiveTenantSession;
use App\Support\Money\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
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

    /**
     * The size of a page is a request, not a filter, and an out-of-range value
     * must fall back rather than let `?per_page=` force a whole-table scan.
     */
    public function test_index_honours_per_page_and_ignores_an_invalid_value(): void
    {
        [$tenant, $admin] = $this->tenantAndAdmin();

        $this->actingAsTenant($tenant);
        $customer = $this->makeCustomer();
        for ($i = 0; $i < 12; $i++) {
            $this->makeOrder($customer, $admin);
        }
        $this->forgetTenant();

        $session = [ActiveTenantSession::KEY => $tenant->getKey()];

        $this->actingAs($admin)->withSession($session)->get('/orders?per_page=10')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('orders.data', 10)
                ->where('orders.meta.per_page', 10)
                ->where('orders.meta.last_page', 2));

        $this->actingAs($admin)->withSession($session)->get('/orders?per_page=10&page=2')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('orders.data', 2)
                ->where('orders.meta.current_page', 2));

        $this->actingAs($admin)->withSession($session)->get('/orders?per_page=999')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('orders.meta.per_page', 25));
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

    /** The toolbar's Channel filter — orders have no channel of their own, so it filters by the customer's (App\Enums\CustomerType). */
    public function test_index_filters_by_channel(): void
    {
        [$tenant, $admin] = $this->tenantAndAdmin();

        $this->actingAsTenant($tenant);
        $wholesale = $this->makeCustomer('Konzum');
        $wholesale->update(['customer_type' => 'WHOLESALE']);
        $retail = $this->makeCustomer('Konoba Fjaka');
        $this->makeOrder($wholesale, $admin);
        $this->makeOrder($retail, $admin);
        $this->forgetTenant();

        $this->actingAs($admin)
            ->withSession([ActiveTenantSession::KEY => $tenant->getKey()])
            ->get('/orders?channel=WHOLESALE')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('orders.data', 1)
                ->where('orders.data.0.customer.company_name', 'Konzum')
                ->where('filters.channel', 'WHOLESALE')
                // Matches the status chips' own rule: every other filter
                // narrows the denominator too, so this isn't a regression —
                // it's the existing chip-count behavior for search/customer.
                ->where('statusCounts.total', 1));
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

    public function test_index_filters_by_item_id(): void
    {
        [$tenant, $admin] = $this->tenantAndAdmin();

        $this->actingAsTenant($tenant);
        $malvasia = $this->makeProduct('Malvazija');
        $plavac = $this->makeProduct('Plavac Mali');
        $withMalvasia = $this->makeOrder($this->makeCustomer('Taverna Olea'), $admin);
        $withMalvasia->items()->create([
            'inventory_item_id' => $malvasia->getKey(),
            'quantity' => 12,
            'unit_type' => 'bottles',
            'unit_price' => Money::fromMinor(2548, 'EUR'),
            'total' => Money::fromMinor(30576, 'EUR'),
        ]);
        $withPlavac = $this->makeOrder($this->makeCustomer('Konoba Kraljevac'), $admin);
        $withPlavac->items()->create([
            'inventory_item_id' => $plavac->getKey(),
            'quantity' => 6,
            'unit_type' => 'bottles',
            'unit_price' => Money::fromMinor(3200, 'EUR'),
            'total' => Money::fromMinor(19200, 'EUR'),
        ]);
        $this->forgetTenant();

        $this->actingAs($admin)
            ->withSession([ActiveTenantSession::KEY => $tenant->getKey()])
            ->get('/orders?item_id='.$malvasia->getKey())
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('orders.data', 1)
                ->where('orders.data.0.customer.company_name', 'Taverna Olea')
                ->where('filters.item_id', $malvasia->getKey())
                ->where('itemFilterName', 'Malvazija'));
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

    /**
     * The design's "Custom" tab. An explicit range beats the preset, so the two
     * controls cannot describe the window at once.
     */
    public function test_an_explicit_date_range_overrides_the_period_preset(): void
    {
        [$tenant, $admin] = $this->tenantAndAdmin();

        $this->actingAsTenant($tenant);
        $customer = $this->makeCustomer();
        $recent = $this->makeOrder($customer, $admin);
        $old = $this->makeOrder($customer, $admin);
        $old->forceFill(['created_at' => now()->subMonths(6)])->saveQuietly();
        $this->forgetTenant();

        $session = [ActiveTenantSession::KEY => $tenant->getKey()];
        $from = now()->subMonths(7)->toDateString();
        $to = now()->subMonths(5)->toDateString();

        // `period=today` would exclude the old order; the range includes only it.
        $this->actingAs($admin)->withSession($session)
            ->get("/orders?period=today&from={$from}&to={$to}")
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('orders.data', 1)
                ->where('orders.data.0.id', $old->getKey())
                ->where('filters.from', $from)
                ->where('filters.to', $to));

        unset($recent);
    }

    /** A hand-edited range must not reach the date parser as anything but a date. */
    public function test_a_malformed_date_range_is_dropped_rather_than_parsed(): void
    {
        [$tenant, $admin] = $this->tenantAndAdmin();

        $this->actingAsTenant($tenant);
        $this->makeOrder($this->makeCustomer(), $admin);
        $this->forgetTenant();

        $this->actingAs($admin)
            ->withSession([ActiveTenantSession::KEY => $tenant->getKey()])
            ->get('/orders?from=yesterday&to=next+tuesday')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('filters.from', null)
                ->where('filters.to', null)
                // Falls back to the default YTD window, which holds the order.
                ->has('orders.data', 1));
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
            ->get('/orders?order='.$order->getKey(), $this->inertiaPartial('Orders/Index', 'order'));

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

    public function test_reacting_to_a_comment_toggles_it_on_then_off(): void
    {
        $tenant = $this->createTenant();
        $member = $this->createMember($tenant, [TenantRole::Orders]);

        $this->actingAsTenant($tenant);
        $order = $this->makeOrder($this->makeCustomer(), $member);
        $note = $order->orderNotes()->create(['content' => 'Hello', 'author_id' => $member->getKey()]);
        $this->forgetTenant();

        $session = [ActiveTenantSession::KEY => $tenant->getKey()];

        $this->actingAs($member)->withSession($session)
            ->post('/order-comments/'.$note->getKey().'/reactions', ['emoji' => '👍'])
            ->assertRedirect();

        $this->actingAsTenant($tenant);
        $this->assertSame(1, $note->reactions()->count());
        $this->forgetTenant();

        // Hitting the same emoji again takes it back.
        $this->actingAs($member)->withSession($session)
            ->post('/order-comments/'.$note->getKey().'/reactions', ['emoji' => '👍'])
            ->assertRedirect();

        $this->actingAsTenant($tenant);
        $this->assertSame(0, $note->reactions()->count());
        $this->forgetTenant();
    }

    public function test_reactions_appear_grouped_on_the_order_detail_read(): void
    {
        $tenant = $this->createTenant();
        $admin = $this->createMember($tenant, [TenantRole::Admin]);
        $member = $this->createMember($tenant, [TenantRole::Orders]);

        $this->actingAsTenant($tenant);
        $order = $this->makeOrder($this->makeCustomer(), $admin);
        $note = $order->orderNotes()->create(['content' => 'Hello', 'author_id' => $admin->getKey()]);
        $note->reactions()->create(['user_id' => $admin->getKey(), 'emoji' => '👍']);
        $note->reactions()->create(['user_id' => $member->getKey(), 'emoji' => '👍']);
        $note->reactions()->create(['user_id' => $admin->getKey(), 'emoji' => '🎉']);
        $this->forgetTenant();

        $response = $this->actingAs($admin)
            ->withSession([ActiveTenantSession::KEY => $tenant->getKey()])
            ->get('/orders?order='.$order->getKey(), $this->inertiaPartial('Orders/Index', 'order'))
            ->assertOk();

        /** @var array<int, array<string, mixed>> $rows */
        $rows = $response->json('props.order.comments.0.reactions');
        $reactions = collect($rows)->keyBy('emoji');

        $thumbsUp = $reactions->get('👍');
        self::assertIsArray($thumbsUp);
        $this->assertSame(2, $thumbsUp['count']);
        $this->assertContains($admin->getKey(), $thumbsUp['user_ids']);
        $this->assertContains($member->getKey(), $thumbsUp['user_ids']);

        $party = $reactions->get('🎉');
        self::assertIsArray($party);
        $this->assertSame(1, $party['count']);
    }

    public function test_reaction_requires_a_recognised_emoji(): void
    {
        $tenant = $this->createTenant();
        $member = $this->createMember($tenant, [TenantRole::Orders]);

        $this->actingAsTenant($tenant);
        $order = $this->makeOrder($this->makeCustomer(), $member);
        $note = $order->orderNotes()->create(['content' => 'Hello', 'author_id' => $member->getKey()]);
        $this->forgetTenant();

        $this->actingAs($member)
            ->withSession([ActiveTenantSession::KEY => $tenant->getKey()])
            ->post('/order-comments/'.$note->getKey().'/reactions', ['emoji' => '💩'])
            ->assertSessionHasErrors('emoji');
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

    /** Places a real order through the same route the Create Order drawer posts to, so its lines are actually priced. */
    private function createOrderViaWeb(Tenant $tenant, User $admin, Customer $customer, InventoryItem $product, int $quantity = 2): Order
    {
        $this->actingAs($admin)
            ->withSession([ActiveTenantSession::KEY => $tenant->getKey()])
            ->post('/orders', [
                'customer_id' => $customer->getKey(),
                'items' => [['inventory_item_id' => $product->getKey(), 'quantity' => $quantity]],
            ])->assertRedirect();

        $this->actingAsTenant($tenant);
        $order = Order::query()->latest('created_at')->firstOrFail();
        $this->forgetTenant();

        return $order;
    }

    public function test_adding_a_line_to_an_existing_order(): void
    {
        [$tenant, $admin] = $this->tenantAndAdmin();

        $this->actingAsTenant($tenant);
        $customer = $this->makeCustomer();
        $product = $this->makeProduct();
        $second = $this->makeProduct('Debit');
        $this->forgetTenant();

        $order = $this->createOrderViaWeb($tenant, $admin, $customer, $product);

        $this->actingAs($admin)
            ->withSession([ActiveTenantSession::KEY => $tenant->getKey()])
            ->post("/orders/{$order->getKey()}/items", [
                'items' => [['inventory_item_id' => $second->getKey(), 'quantity' => 1]],
            ])->assertRedirect();

        $this->actingAsTenant($tenant);
        $this->assertSame(2, $order->items()->count());
        $this->forgetTenant();
    }

    public function test_updating_a_lines_quantity_adjusts_the_order_total(): void
    {
        [$tenant, $admin] = $this->tenantAndAdmin();

        $this->actingAsTenant($tenant);
        $customer = $this->makeCustomer();
        $product = $this->makeProduct();
        $this->forgetTenant();

        $order = $this->createOrderViaWeb($tenant, $admin, $customer, $product, 2);

        $this->actingAsTenant($tenant);
        $item = $order->items()->firstOrFail();
        $unitPrice = $item->unit_price->getMinorAmount();
        $this->forgetTenant();

        $this->actingAs($admin)
            ->withSession([ActiveTenantSession::KEY => $tenant->getKey()])
            ->patch("/order-items/{$item->getKey()}", ['quantity' => 5])
            ->assertRedirect();

        $this->actingAsTenant($tenant);
        $this->assertSame($unitPrice * 5, $order->refresh()->total_amount->getMinorAmount());
        $this->forgetTenant();
    }

    public function test_deleting_the_only_line_is_refused(): void
    {
        [$tenant, $admin] = $this->tenantAndAdmin();

        $this->actingAsTenant($tenant);
        $customer = $this->makeCustomer();
        $product = $this->makeProduct();
        $this->forgetTenant();

        $order = $this->createOrderViaWeb($tenant, $admin, $customer, $product);

        $this->actingAsTenant($tenant);
        $item = $order->items()->firstOrFail();
        $this->forgetTenant();

        $this->actingAs($admin)
            ->withSession([ActiveTenantSession::KEY => $tenant->getKey()])
            ->delete("/order-items/{$item->getKey()}")
            ->assertSessionHasErrors(['item']);

        $this->actingAsTenant($tenant);
        $this->assertSame(1, $order->items()->count());
        $this->forgetTenant();
    }

    public function test_deleting_a_line_that_is_not_the_last_succeeds(): void
    {
        [$tenant, $admin] = $this->tenantAndAdmin();

        $this->actingAsTenant($tenant);
        $customer = $this->makeCustomer();
        $product = $this->makeProduct();
        $second = $this->makeProduct('Debit');
        $this->forgetTenant();

        $order = $this->createOrderViaWeb($tenant, $admin, $customer, $product);

        $this->actingAs($admin)
            ->withSession([ActiveTenantSession::KEY => $tenant->getKey()])
            ->post("/orders/{$order->getKey()}/items", [
                'items' => [['inventory_item_id' => $second->getKey(), 'quantity' => 1]],
            ])->assertRedirect();

        $this->actingAsTenant($tenant);
        $extra = $order->items()->latest('id')->firstOrFail();
        $this->forgetTenant();

        $this->actingAs($admin)
            ->withSession([ActiveTenantSession::KEY => $tenant->getKey()])
            ->delete("/order-items/{$extra->getKey()}")
            ->assertRedirect();

        $this->actingAsTenant($tenant);
        $this->assertSame(1, $order->items()->count());
        $this->forgetTenant();
    }

    public function test_edit_window_blocks_a_restricted_member_but_not_the_admin(): void
    {
        [$tenant, $admin] = $this->tenantAndAdmin();

        $this->actingAsTenant($tenant);
        $customer = $this->makeCustomer();
        $product = $this->makeProduct();
        $this->forgetTenant();

        $order = $this->createOrderViaWeb($tenant, $admin, $customer, $product);

        $this->actingAsTenant($tenant);
        $item = $order->items()->firstOrFail();
        $order->forceFill(['created_at' => now()->subHours(2)])->save();
        $this->forgetTenant();

        // Orders holds orders.manage but not the per-membership can_edit_orders flag.
        $teamMember = $this->createMember($tenant, [TenantRole::Team]);
        $this->actingAs($teamMember)
            ->withSession([ActiveTenantSession::KEY => $tenant->getKey()])
            ->patch("/order-items/{$item->getKey()}", ['quantity' => 1])
            ->assertForbidden();

        $this->actingAs($admin)
            ->withSession([ActiveTenantSession::KEY => $tenant->getKey()])
            ->patch("/order-items/{$item->getKey()}", ['quantity' => 1])
            ->assertRedirect();
    }

    public function test_duplicating_an_order_copies_lines_but_starts_a_fresh_order(): void
    {
        [$tenant, $admin] = $this->tenantAndAdmin();

        $this->actingAsTenant($tenant);
        $customer = $this->makeCustomer();
        $product = $this->makeProduct();
        $this->forgetTenant();

        $order = $this->createOrderViaWeb($tenant, $admin, $customer, $product, 3);
        $this->actingAsTenant($tenant);
        $order->orderNotes()->create(['content' => 'Original comment', 'author_id' => $admin->getKey()]);
        $this->forgetTenant();

        $response = $this->actingAs($admin)
            ->withSession([ActiveTenantSession::KEY => $tenant->getKey()])
            ->post("/orders/{$order->getKey()}/duplicate");

        $this->actingAsTenant($tenant);
        $duplicate = Order::query()->where('id', '!=', $order->getKey())->firstOrFail();
        $this->forgetTenant();

        $response->assertRedirect('/orders?order='.$duplicate->getKey());

        $this->actingAsTenant($tenant);
        $this->assertNotSame($order->order_number, $duplicate->order_number);
        $this->assertSame(OrderStatus::Received, $duplicate->status);
        $this->assertSame($customer->getKey(), $duplicate->customer_id);
        $this->assertSame(1, $duplicate->items()->count());
        $this->assertSame(3, $duplicate->items()->firstOrFail()->quantity);
        $this->assertSame(0, $duplicate->orderNotes()->count());
        $this->assertSame(1, $duplicate->statusHistories()->count());
        $this->forgetTenant();
    }

    public function test_export_streams_a_csv_honouring_filters_and_shipped_visibility(): void
    {
        $tenant = $this->createTenant();
        $member = $this->createMember($tenant, [TenantRole::Orders]);

        $this->actingAsTenant($tenant);
        $customer = $this->makeCustomer();
        $visible = $this->makeOrder($customer, $member, OrderStatus::Received);
        $this->makeOrder($customer, $member, OrderStatus::Shipped);
        $this->forgetTenant();

        $response = $this->actingAs($member)
            ->withSession([ActiveTenantSession::KEY => $tenant->getKey()])
            ->get('/orders/export')
            ->assertOk();

        self::assertStringContainsString('text/csv', (string) $response->headers->get('Content-Type'));
        $csv = $response->streamedContent();
        self::assertStringContainsString($visible->order_number, $csv);
        // ORDERS lacks can_see_shipped_orders — the export must not leak it
        // any more than the table itself does.
        self::assertStringNotContainsString('SHIPPED', $csv);
    }

    public function test_export_requires_orders_view(): void
    {
        $tenant = $this->createTenant();
        $cellar = $this->createMember($tenant, [TenantRole::Cellar]);

        $this->actingAs($cellar)
            ->withSession([ActiveTenantSession::KEY => $tenant->getKey()])
            ->get('/orders/export')
            ->assertForbidden();
    }

    public function test_bulk_status_change_moves_every_selected_order_and_records_history(): void
    {
        [$tenant, $admin] = $this->tenantAndAdmin();

        $this->actingAsTenant($tenant);
        $customer = $this->makeCustomer();
        $first = $this->makeOrder($customer, $admin, OrderStatus::Received);
        $second = $this->makeOrder($customer, $admin, OrderStatus::Received);
        $this->forgetTenant();

        $this->actingAs($admin)
            ->withSession([ActiveTenantSession::KEY => $tenant->getKey()])
            ->patch('/orders-bulk/status', [
                'order_ids' => [$first->getKey(), $second->getKey()],
                'status' => 'IN_PROCESS',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->actingAsTenant($tenant);
        self::assertSame(OrderStatus::InProcess, $first->refresh()->status);
        self::assertSame(OrderStatus::InProcess, $second->refresh()->status);
        self::assertSame(2, $first->statusHistories()->count());
        self::assertSame(2, $second->statusHistories()->count());
        $this->forgetTenant();
    }

    public function test_bulk_status_change_requires_orders_manage(): void
    {
        $tenant = $this->createTenant();
        $sales = $this->createMember($tenant, [TenantRole::Sales]);

        $this->actingAs($sales)
            ->withSession([ActiveTenantSession::KEY => $tenant->getKey()])
            ->patch('/orders-bulk/status', ['order_ids' => ['x'], 'status' => 'IN_PROCESS'])
            ->assertForbidden();
    }

    /** The overflow menu's "Mark paid" — records an Inflow, payment state is derived from it. */
    public function test_mark_paid_records_an_inflow_for_the_outstanding_balance(): void
    {
        [$tenant, $admin] = $this->tenantAndAdmin();

        $this->actingAsTenant($tenant);
        $order = $this->makeOrder($this->makeCustomer(), $admin);
        $this->forgetTenant();

        $this->actingAs($admin)
            ->withSession([ActiveTenantSession::KEY => $tenant->getKey()])
            ->post("/orders/{$order->getKey()}/mark-paid")
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->actingAsTenant($tenant);
        $inflow = Inflow::query()->where('order_id', $order->getKey())->firstOrFail();
        self::assertSame($order->total_amount->getMinorAmount(), $inflow->amount->getMinorAmount());
        self::assertSame($order->customer_id, $inflow->customer_id);
        $this->forgetTenant();
    }

    public function test_marking_an_already_paid_order_paid_again_is_refused(): void
    {
        [$tenant, $admin] = $this->tenantAndAdmin();

        $this->actingAsTenant($tenant);
        $order = $this->makeOrder($this->makeCustomer(), $admin);
        $this->forgetTenant();

        $session = [ActiveTenantSession::KEY => $tenant->getKey()];
        $this->actingAs($admin)->withSession($session)->post("/orders/{$order->getKey()}/mark-paid")->assertRedirect();

        $this->actingAs($admin)->withSession($session)
            ->post("/orders/{$order->getKey()}/mark-paid")
            ->assertSessionHasErrors('order');
    }

    /** The overflow menu's "Resend" — the same confirmation a customer got when the order was placed. */
    public function test_resend_confirmation_emails_the_customer(): void
    {
        Notification::fake();

        [$tenant, $admin] = $this->tenantAndAdmin();

        $this->actingAsTenant($tenant);
        $order = $this->makeOrder($this->makeCustomer(), $admin);
        $this->forgetTenant();

        $this->actingAs($admin)
            ->withSession([ActiveTenantSession::KEY => $tenant->getKey()])
            ->post("/orders/{$order->getKey()}/resend-confirmation")
            ->assertRedirect()
            ->assertSessionHas('success');

        Notification::assertSentOnDemand(OrderConfirmationNotification::class);
    }

    /** The overflow menu's "Print" — a packing-slip/invoice PDF of the same order. */
    public function test_download_pdf_streams_a_pdf(): void
    {
        [$tenant, $admin] = $this->tenantAndAdmin();

        $this->actingAsTenant($tenant);
        $order = $this->makeOrder($this->makeCustomer(), $admin);
        $this->forgetTenant();

        $response = $this->actingAs($admin)
            ->withSession([ActiveTenantSession::KEY => $tenant->getKey()])
            ->get("/orders/{$order->getKey()}/pdf")
            ->assertOk();

        self::assertStringContainsString('application/pdf', (string) $response->headers->get('Content-Type'));
    }

    /**
     * The drawer's Profitability card: a fresh order (written after
     * unit_price_gross existed) shows the real Gross/Rebate/Net breakdown.
     */
    public function test_profitability_reports_gross_and_rebate_for_a_rebated_customer(): void
    {
        [$tenant, $admin] = $this->tenantAndAdmin();

        $this->actingAsTenant($tenant);
        $customer = $this->makeCustomer();
        $customer->update(['rebate_percent' => '10']);
        $product = $this->makeProduct(); // default_price 2548
        $this->forgetTenant();

        $order = $this->createOrderViaWeb($tenant, $admin, $customer, $product, 2);

        $response = $this->actingAs($admin)
            ->withSession([ActiveTenantSession::KEY => $tenant->getKey()])
            ->get('/orders?order='.$order->getKey(), $this->inertiaPartial('Orders/Index', 'order'));

        $response->assertOk();
        // 2548 * 2 = 5096 gross; 10% rebate -> 4586 net (2293/unit, rounded).
        self::assertSame(5096, $response->json('props.order.profitability.gross_revenue.minor'));
        self::assertSame(510, $response->json('props.order.profitability.rebate_amount.minor'));
    }
}
