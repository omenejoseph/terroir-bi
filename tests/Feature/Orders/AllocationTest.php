<?php

declare(strict_types=1);

namespace Tests\Feature\Orders;

use App\Actions\Orders\AddOrderItemsAction;
use App\Actions\Orders\BulkUpdateOrderStatusAction;
use App\Actions\Orders\CreateOrderAction;
use App\Actions\Orders\DeleteOrderAction;
use App\Actions\Orders\DeleteOrderItemAction;
use App\Actions\Orders\DuplicateOrderAction;
use App\Actions\Orders\UpdateOrderItemAction;
use App\Actions\Orders\UpdateOrderStatusAction;
use App\Enums\OrderStatus;
use App\Exceptions\InsufficientStockException;
use App\Models\Customer;
use App\Models\InventoryItem;
use App\Models\Order;
use App\Models\StockMovement;
use App\Queries\ItemAvailabilityQuery;
use App\Services\Auth\ActiveTenantSession;
use App\Services\Orders\AllocationService;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Essentials\EssentialsScenarioTestCase;

/**
 * Allocation orders: stock set aside for a customer. It stays in the cellar (no ledger movement, on hand
 * unchanged) but is not free to sell, and leaves for real when the order ships. The three stock modes —
 * backorder, consignment, allocation — are mutually exclusive.
 */
class AllocationTest extends EssentialsScenarioTestCase
{
    /** @param  array<string, mixed>  $extra */
    private function allocate(InventoryItem $item, Customer $customer, int $quantity, array $extra = []): Order
    {
        return app(CreateOrderAction::class)->execute($customer, $this->admin->getKey(), [
            'is_allocation' => true,
            'items' => [['inventory_item_id' => $item->getKey(), 'quantity' => $quantity, 'unit_type' => 'bottles']],
            ...$extra,
        ]);
    }

    private function order(InventoryItem $item, Customer $customer, int $quantity): Order
    {
        return app(CreateOrderAction::class)->execute($customer, $this->admin->getKey(), [
            'items' => [['inventory_item_id' => $item->getKey(), 'quantity' => $quantity, 'unit_type' => 'bottles']],
        ]);
    }

    /** @return array<string, mixed> */
    private function position(InventoryItem $item): array
    {
        return app(ItemAvailabilityQuery::class)->get($item->refresh());
    }

    /* ---- creating one -------------------------------------------------- */

    public function test_an_allocation_sets_stock_aside_without_moving_it(): void
    {
        $wine = $this->givenBottledWine(['current_stock' => '100.000']);
        $customer = $this->givenCustomer(['company_name' => 'Konoba Dalmacija']);

        $order = $this->allocate($wine, $customer, 30);

        $this->assertTrue($order->is_allocation);
        $this->assertFalse($order->deduct_stock);
        $this->assertTrue($order->holdsAllocation());
        // Still in the cellar: on hand is unchanged and the ledger has nothing for it.
        $this->assertSame(100.0, $this->stockOf($wine));
        $this->assertCount(0, $this->deductionsOf($wine));

        $position = $this->position($wine);
        $this->assertSame('100.000', $position['on_hand']);
        $this->assertSame('30.000', $position['allocated']);
        $this->assertSame('70.000', $position['free_to_sell']);
        $this->assertSame([[
            'order_id' => $order->getKey(), 'order_number' => $order->order_number,
            'customer' => 'Konoba Dalmacija', 'quantity' => '30.000',
        ]], $position['allocations']);
    }

    public function test_cases_are_counted_in_the_items_own_unit(): void
    {
        // Stored in cases of 6: 12 bottles set aside is 2 cases.
        $wine = $this->givenCasedWine(['current_stock' => '10.000']);

        $this->allocate($wine, $this->givenCustomer(), 12);

        $this->assertSame('2.000', $this->position($wine)['allocated']);
        $this->assertSame('8.000', $this->position($wine)['free_to_sell']);
    }

    public function test_more_cannot_be_allocated_than_the_cellar_holds(): void
    {
        $wine = $this->givenBottledWine(['current_stock' => '20.000']);
        $this->allocate($wine, $this->givenCustomer(), 15);

        try {
            $this->allocate($wine, $this->givenCustomer(), 10);
            $this->fail('Expected the over-allocation to be refused.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('items', $e->errors());
        }

        // The refused order left nothing behind.
        $this->assertSame(1, Order::query()->where('is_allocation', true)->count());
        $this->assertSame('15.000', $this->position($wine)['allocated']);
    }

    public function test_custom_lines_set_nothing_aside(): void
    {
        $order = app(CreateOrderAction::class)->execute($this->givenCustomer(), $this->admin->getKey(), [
            'is_allocation' => true,
            'items' => [['custom_description' => 'Gift box', 'quantity' => 1, 'unit_price' => 500]],
        ]);

        $this->assertTrue($order->holdsAllocation());
    }

    /* ---- what it protects ------------------------------------------------ */

    public function test_another_order_cannot_take_what_is_set_aside(): void
    {
        $wine = $this->givenBottledWine(['current_stock' => '20.000']);
        $this->allocate($wine, $this->givenCustomer(), 15);

        // 5 are free; an order for 10 would eat into the allocation.
        try {
            $this->order($wine, $this->givenCustomer(), 10);
            $this->fail('Expected the order to be refused.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString(__('free to sell'), $e->errors()['items'][0]);
        }
        $this->assertSame(20.0, $this->stockOf($wine));

        // What is free can still be sold.
        $this->order($wine, $this->givenCustomer(), 5);
        $this->assertSame(15.0, $this->stockOf($wine));
        $this->assertSame('0.000', $this->position($wine)['free_to_sell']);
    }

    public function test_adding_lines_to_an_order_is_held_to_the_same_rule(): void
    {
        $wine = $this->givenBottledWine(['current_stock' => '20.000']);
        $other = $this->givenBottledWine(['name' => 'Other', 'current_stock' => '100.000']);
        $this->allocate($wine, $this->givenCustomer(), 18);
        $order = $this->order($other, $this->givenCustomer(), 1);

        $this->expectException(ValidationException::class);
        app(AddOrderItemsAction::class)->execute($order, [['inventory_item_id' => $wine->getKey(), 'quantity' => 5, 'unit_type' => 'bottles']]);
    }

    public function test_an_item_with_no_allocation_behaves_exactly_as_before(): void
    {
        $wine = $this->givenBottledWine(['current_stock' => '10.000']);

        // The ledger's own "not enough stock" error, untouched.
        $this->expectException(InsufficientStockException::class);
        $this->order($wine, $this->givenCustomer(), 11);
    }

    /* ---- its life ----------------------------------------------------------- */

    public function test_shipping_an_allocation_takes_the_stock_and_releases_the_reservation(): void
    {
        $wine = $this->givenBottledWine(['current_stock' => '100.000']);
        $order = $this->allocate($wine, $this->givenCustomer(), 30);

        app(UpdateOrderStatusAction::class)->execute($order, OrderStatus::Shipped, null, $this->admin->getKey());

        $order->refresh();
        $this->assertTrue($order->deduct_stock);
        $this->assertFalse($order->holdsAllocation());
        $this->assertSame(OrderStatus::Shipped, $order->status);
        // It left the cellar now, with a movement for it.
        $this->assertSame(70.0, $this->stockOf($wine));
        $this->assertCount(1, $this->deductionsOf($wine));
        $this->assertSame($order->order_number, $this->deductionsOf($wine)[0]->reference);
        // And is no longer set aside.
        $position = $this->position($wine);
        $this->assertSame('0.000', $position['allocated']);
        $this->assertSame('70.000', $position['free_to_sell']);
    }

    public function test_moving_through_the_pipeline_before_shipping_takes_nothing(): void
    {
        $wine = $this->givenBottledWine(['current_stock' => '100.000']);
        $order = $this->allocate($wine, $this->givenCustomer(), 30);

        app(UpdateOrderStatusAction::class)->execute($order, OrderStatus::InProcess, null, $this->admin->getKey());
        app(UpdateOrderStatusAction::class)->execute($order, OrderStatus::ReadyToShip, null, $this->admin->getKey());

        $this->assertSame(100.0, $this->stockOf($wine));
        $this->assertTrue($order->refresh()->holdsAllocation());
    }

    public function test_it_does_not_ship_if_the_cellar_no_longer_holds_the_goods(): void
    {
        $wine = $this->givenBottledWine(['current_stock' => '100.000']);
        $order = $this->allocate($wine, $this->givenCustomer(), 30);
        // Breakage / a count correction: only 10 left although 30 are promised.
        $wine->forceFill(['current_stock' => '10.000'])->save();

        try {
            app(UpdateOrderStatusAction::class)->execute($order, OrderStatus::Shipped, null, $this->admin->getKey());
            $this->fail('Expected the shipment to be refused.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('status', $e->errors());
        }

        $order->refresh();
        $this->assertNotSame(OrderStatus::Shipped, $order->status);
        $this->assertTrue($order->holdsAllocation());
        $this->assertSame(10.0, $this->stockOf($wine));
        // The shortfall is visible rather than hidden.
        $this->assertSame('-20.000', $this->position($wine)['free_to_sell']);
    }

    public function test_bulk_shipping_converts_each_allocation(): void
    {
        $wine = $this->givenBottledWine(['current_stock' => '100.000']);
        $a = $this->allocate($wine, $this->givenCustomer(), 10);
        $b = $this->allocate($wine, $this->givenCustomer(), 20);

        app(BulkUpdateOrderStatusAction::class)->execute([$a->getKey(), $b->getKey()], OrderStatus::Shipped, null, $this->admin->getKey());

        $this->assertSame(70.0, $this->stockOf($wine));
        $this->assertSame('0.000', $this->position($wine)['allocated']);
    }

    public function test_deleting_an_allocation_just_lets_the_reservation_go(): void
    {
        $wine = $this->givenBottledWine(['current_stock' => '100.000']);
        $order = $this->allocate($wine, $this->givenCustomer(), 30);

        app(DeleteOrderAction::class)->execute($order);

        // Nothing had been taken, so nothing is put back.
        $this->assertSame(100.0, $this->stockOf($wine));
        $this->assertSame(0, StockMovement::query()->where('inventory_item_id', $wine->getKey())->count());
        $this->assertSame('0.000', $this->position($wine)['allocated']);
    }

    public function test_deleting_a_shipped_allocation_restocks_like_any_shipped_order(): void
    {
        $wine = $this->givenBottledWine(['current_stock' => '100.000']);
        $order = $this->allocate($wine, $this->givenCustomer(), 30);
        app(UpdateOrderStatusAction::class)->execute($order, OrderStatus::Shipped, null, $this->admin->getKey());

        app(DeleteOrderAction::class)->execute($order->refresh());

        $this->assertSame(100.0, $this->stockOf($wine));
    }

    public function test_editing_an_allocated_line_moves_no_stock_but_must_still_fit(): void
    {
        $wine = $this->givenBottledWine(['current_stock' => '40.000']);
        $order = $this->allocate($wine, $this->givenCustomer(), 10);
        $line = $order->items()->firstOrFail();

        app(UpdateOrderItemAction::class)->execute($line, 25, null);
        $this->assertSame(40.0, $this->stockOf($wine));
        $this->assertSame('25.000', $this->position($wine)['allocated']);
        $this->assertSame(0, StockMovement::query()->where('inventory_item_id', $wine->getKey())->count());

        $this->expectException(ValidationException::class);
        app(UpdateOrderItemAction::class)->execute($line->refresh(), 41, null);
    }

    public function test_removing_an_allocated_line_puts_nothing_back(): void
    {
        $wine = $this->givenBottledWine(['current_stock' => '40.000']);
        $other = $this->givenBottledWine(['name' => 'Other', 'current_stock' => '40.000']);
        $order = app(CreateOrderAction::class)->execute($this->givenCustomer(), $this->admin->getKey(), [
            'is_allocation' => true,
            'items' => [
                ['inventory_item_id' => $wine->getKey(), 'quantity' => 10, 'unit_type' => 'bottles'],
                ['inventory_item_id' => $other->getKey(), 'quantity' => 5, 'unit_type' => 'bottles'],
            ],
        ]);

        app(DeleteOrderItemAction::class)->execute($order->items()->where('inventory_item_id', $wine->getKey())->firstOrFail());

        $this->assertSame(40.0, $this->stockOf($wine));
        $this->assertSame('0.000', $this->position($wine)['allocated']);
        $this->assertSame('5.000', $this->position($other)['allocated']);
    }

    public function test_a_duplicate_is_a_fresh_decision_not_another_allocation(): void
    {
        $wine = $this->givenBottledWine(['current_stock' => '100.000']);
        $order = $this->allocate($wine, $this->givenCustomer(), 10);

        $copy = Order::query()->whereKey(app(DuplicateOrderAction::class)->execute($order, $this->admin->getKey())->getKey())->firstOrFail();

        $this->assertFalse($copy->is_allocation);
        $this->assertTrue($copy->deduct_stock);
    }

    /* ---- the three modes exclude each other ---------------------------------- */

    /** @return array<string, array{0: array<string, bool>, 1: string}> */
    public static function conflictingModes(): array
    {
        return [
            'consignment and backorder' => [['is_consignment' => true, 'is_backorder' => true], 'is_consignment'],
            'allocation and backorder' => [['is_allocation' => true, 'is_backorder' => true], 'is_allocation'],
            'allocation and consignment' => [['is_allocation' => true, 'is_consignment' => true], 'is_allocation'],
            'all three' => [['is_allocation' => true, 'is_consignment' => true, 'is_backorder' => true], 'is_allocation'],
        ];
    }

    /**
     * @param  array<string, bool>  $modes
     *
     * @dataProvider conflictingModes
     */
    #[DataProvider('conflictingModes')]
    public function test_modes_cannot_be_combined(array $modes, string $field): void
    {
        $wine = $this->givenBottledWine(['current_stock' => '100.000']);

        try {
            app(CreateOrderAction::class)->execute($this->givenCustomer(), $this->admin->getKey(), [
                ...$modes,
                'items' => [['inventory_item_id' => $wine->getKey(), 'quantity' => 1, 'unit_type' => 'bottles']],
            ]);
            $this->fail('Expected the combination to be refused.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey($field, $e->errors());
        }

        $this->assertSame(0, Order::query()->count());
        $this->assertSame(100.0, $this->stockOf($wine));
    }

    public function test_an_allocation_cannot_start_out_shipped_or_deducting(): void
    {
        $wine = $this->givenBottledWine(['current_stock' => '100.000']);
        $items = [['inventory_item_id' => $wine->getKey(), 'quantity' => 1, 'unit_type' => 'bottles']];

        foreach ([['status' => 'SHIPPED', 'field' => 'status'], ['deduct_stock' => true, 'field' => 'is_allocation']] as $case) {
            $field = $case['field'];
            unset($case['field']);

            try {
                app(CreateOrderAction::class)->execute($this->givenCustomer(), $this->admin->getKey(), ['is_allocation' => true, 'items' => $items, ...$case]);
                $this->fail("Expected {$field} to be refused.");
            } catch (ValidationException $e) {
                $this->assertArrayHasKey($field, $e->errors());
            }
        }

        $this->assertSame(0, Order::query()->count());
    }

    public function test_each_mode_on_its_own_is_fine(): void
    {
        $wine = $this->givenBottledWine(['current_stock' => '100.000']);
        $items = [['inventory_item_id' => $wine->getKey(), 'quantity' => 1, 'unit_type' => 'bottles']];

        foreach ([['is_backorder' => true], ['is_consignment' => true], ['is_allocation' => true], []] as $modes) {
            $order = app(CreateOrderAction::class)->execute($this->givenCustomer(), $this->admin->getKey(), [...$modes, 'items' => $items]);
            $this->assertNotNull($order->getKey());
        }
    }

    public function test_the_order_form_request_enforces_the_same_rule(): void
    {
        $wine = $this->givenBottledWine(['current_stock' => '100.000']);
        $customer = $this->givenCustomer();
        $this->forgetTenant();

        $this->actingAs($this->admin)->withSession([ActiveTenantSession::KEY => $this->tenant->getKey()])
            ->post('/orders', [
                'customer_id' => $customer->getKey(), 'is_allocation' => true, 'is_consignment' => true,
                'items' => [['inventory_item_id' => $wine->getKey(), 'quantity' => 1, 'unit_type' => 'bottles']],
            ])
            ->assertSessionHasErrors('is_allocation');

        $this->actingAs($this->admin)->withSession([ActiveTenantSession::KEY => $this->tenant->getKey()])
            ->post('/orders', [
                'customer_id' => $customer->getKey(), 'is_allocation' => true,
                'items' => [['inventory_item_id' => $wine->getKey(), 'quantity' => 4, 'unit_type' => 'bottles']],
            ])
            ->assertRedirect();

        $this->actingAsTenant($this->tenant);
        $this->assertSame(1, Order::query()->where('is_allocation', true)->count());
        $this->assertSame(100.0, $this->stockOf($wine));
    }

    /* ---- the waterfall in the item drawer --------------------------------------- */

    public function test_open_orders_and_consignment_are_reported_but_not_subtracted_again(): void
    {
        $wine = $this->givenBottledWine(['current_stock' => '100.000']);
        $customer = $this->givenCustomer();

        $this->order($wine, $customer, 12); // open, already off the shelf
        app(CreateOrderAction::class)->execute($customer, $this->admin->getKey(), [
            'is_consignment' => true,
            'items' => [['inventory_item_id' => $wine->getKey(), 'quantity' => 6, 'unit_type' => 'bottles']],
        ]);
        $this->allocate($wine, $customer, 20);

        $position = $this->position($wine);
        // 100 − 12 − 6 are physically here; of those, 20 are set aside.
        $this->assertSame('82.000', $position['on_hand']);
        $this->assertSame('20.000', $position['allocated']);
        $this->assertSame('62.000', $position['free_to_sell']);
        $this->assertSame('12.000', $position['in_open_orders']);
        $this->assertSame('6.000', $position['on_consignment']);
    }

    public function test_a_shipped_order_is_no_longer_counted_as_open(): void
    {
        $wine = $this->givenBottledWine(['current_stock' => '100.000']);
        $order = $this->order($wine, $this->givenCustomer(), 12);
        $this->assertSame('12.000', $this->position($wine)['in_open_orders']);

        app(UpdateOrderStatusAction::class)->execute($order, OrderStatus::Shipped, null, $this->admin->getKey());

        $this->assertSame('0.000', $this->position($wine)['in_open_orders']);
    }

    public function test_the_drawer_asks_for_the_figures_through_a_partial_reload(): void
    {
        $wine = $this->givenBottledWine(['current_stock' => '100.000']);
        $this->allocate($wine, $this->givenCustomer(), 30);
        $this->forgetTenant();

        $this->actingAs($this->admin)->withSession([ActiveTenantSession::KEY => $this->tenant->getKey()])
            ->get('/inventory?item='.$wine->getKey(), $this->inertiaPartial('Inventory/Index', ['itemAvailability']))
            ->assertOk()
            ->assertJsonPath('props.itemAvailability.item_id', $wine->getKey())
            ->assertJsonPath('props.itemAvailability.free_to_sell', '70.000')
            ->assertJsonCount(1, 'props.itemAvailability.allocations');
    }

    public function test_the_service_agrees_with_the_query(): void
    {
        $wine = $this->givenBottledWine(['current_stock' => '50.000']);
        $this->allocate($wine, $this->givenCustomer(), 8);
        $this->allocate($wine, $this->givenCustomer(), 7);

        $this->assertSame('15.000', app(AllocationService::class)->allocated($wine));
        $this->assertSame('35.000', app(AllocationService::class)->freeToSell($wine->refresh()));
    }
}
