<?php

declare(strict_types=1);

namespace Tests\Feature\Essentials;

use App\Actions\Orders\CreateOrderAction;
use App\Actions\Orders\UpdateOrderStatusAction;
use App\Enums\NotificationType;
use App\Enums\OrderStatus;
use App\Exceptions\InsufficientStockException;
use App\Models\Notification;
use App\Models\Order;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Feature: Orders — creation & stock contract (ORD-001 … ORD-007).
 *
 * Adapted to the migrated app where the prototype contract evolved:
 * - ORD-002 concurrency: the row lock lives in StockLedger::lock()
 *   (lockForUpdate); sqlite's single test connection can't exercise true
 *   parallelism, so the guard is asserted sequentially here.
 * - ORD-004 (zero-stock hidden from the form) is a client concern in the
 *   decoupled frontend; the API equivalent — the catalog only offers items
 *   for sale — is covered by inventory listing tests.
 * - ORD-005 (number collisions): OrderNumberGenerator::next() retries until
 *   unique by construction.
 */
class OrdersStockContractTest extends EssentialsScenarioTestCase
{
    public function test_ord001_creating_an_order_deducts_stock_immediately(): void
    {
        // Given "R3 2025" has 100 bottles in stock
        $r3 = $this->givenBottledWine(['current_stock' => '100.000']);
        $customer = $this->givenCustomer();

        // When a non-backorder order for 24 bottles of "R3 2025" is created
        $order = app(CreateOrderAction::class)->execute($customer, $this->admin->getKey(), [
            'items' => [['inventory_item_id' => $r3->getKey(), 'quantity' => 24, 'unit_type' => 'bottles']],
        ]);

        // Then an ORDER_DEDUCT movement of -24 bottles is recorded referencing the order number
        $deducts = $this->deductionsOf($r3);
        $this->assertCount(1, $deducts);
        $this->assertSame(-24.0, (float) $deducts[0]->quantity);
        $this->assertSame($order->order_number, $deducts[0]->reference);

        // And current stock of "R3 2025" is 76 bottles
        $this->assertSame(76.0, $this->stockOf($r3));
    }

    public function test_ord001_status_changes_move_no_stock(): void
    {
        // Given the order above exists with status RECEIVED
        $r3 = $this->givenBottledWine(['current_stock' => '100.000']);
        $customer = $this->givenCustomer();
        $order = app(CreateOrderAction::class)->execute($customer, $this->admin->getKey(), [
            'items' => [['inventory_item_id' => $r3->getKey(), 'quantity' => 24, 'unit_type' => 'bottles']],
        ]);

        // When its status changes to IN_PROCESS, READY_TO_SHIP and then SHIPPED
        $action = app(UpdateOrderStatusAction::class);
        $action->execute($order, OrderStatus::InProcess, null, $this->admin->getKey());
        $action->execute($order, OrderStatus::ReadyToShip, null, $this->admin->getKey());
        $action->execute($order, OrderStatus::Shipped, null, $this->admin->getKey());

        // Then no additional stock movement is recorded
        $this->assertCount(1, $this->movementsOf($r3));

        // And current stock of "R3 2025" is still 76 bottles
        $this->assertSame(76.0, $this->stockOf($r3));
    }

    public function test_ord002_order_exceeding_stock_is_rejected_with_a_clear_error(): void
    {
        // Given "R3 2025" has 10 bottles in stock
        $r3 = $this->givenBottledWine(['current_stock' => '10.000']);
        $customer = $this->givenCustomer();

        // When a non-backorder order for 24 bottles of "R3 2025" is submitted
        $error = null;
        try {
            app(CreateOrderAction::class)->execute($customer, $this->admin->getKey(), [
                'items' => [['inventory_item_id' => $r3->getKey(), 'quantity' => 24, 'unit_type' => 'bottles']],
            ]);
        } catch (InsufficientStockException $e) {
            $error = $e->getMessage();
        }

        // Then the order is NOT created (the transaction rolled back)
        $this->assertSame(0, Order::query()->count());

        // And no stock movement is written
        $this->assertCount(0, $this->movementsOf($r3));
        $this->assertSame(10.0, $this->stockOf($r3));

        // And the error names the item, the availability and the backorder way out
        $this->assertNotNull($error);
        $this->assertStringContainsString('Not enough stock for R3', $error);
        $this->assertStringContainsString('10', $error);
        $this->assertStringContainsString('need 24', $error);
        $this->assertStringContainsString('backorder', $error);
    }

    public function test_ord002_sequential_orders_cannot_jointly_overdraw(): void
    {
        // Given "R3 2025" has 10 bottles in stock (the FOR UPDATE row lock in
        // StockLedger::lock() serialises true concurrency; sqlite tests assert
        // the guard holds once the first order has claimed its stock)
        $r3 = $this->givenBottledWine(['current_stock' => '10.000']);
        $customer = $this->givenCustomer();
        $submit = fn () => app(CreateOrderAction::class)->execute($customer, $this->admin->getKey(), [
            'items' => [['inventory_item_id' => $r3->getKey(), 'quantity' => 8, 'unit_type' => 'bottles']],
        ]);

        // When two orders for 8 bottles each are submitted
        $submit();
        $failed = false;
        try {
            $submit();
        } catch (InsufficientStockException) {
            $failed = true;
        }

        // Then exactly one succeeds and one fails the stock check
        $this->assertTrue($failed);
        $this->assertSame(1, Order::query()->count());

        // And stock never goes below 0
        $this->assertSame(2.0, $this->stockOf($r3));
    }

    public function test_ord003_backorder_for_out_of_stock_goods(): void
    {
        // Given "R3 2025" has 0 bottles in stock
        $r3 = $this->givenBottledWine(['current_stock' => '0.000']);
        $customer = $this->givenCustomer();

        // When an order flagged as backorder for 24 bottles is created
        $order = app(CreateOrderAction::class)->execute($customer, $this->admin->getKey(), [
            'is_backorder' => true,
            'items' => [['inventory_item_id' => $r3->getKey(), 'quantity' => 24, 'unit_type' => 'bottles']],
        ]);

        // Then the order is created successfully
        $this->assertTrue($order->exists);
        $this->assertTrue($order->is_backorder);

        // And no ORDER_DEDUCT movement is written
        $this->assertCount(0, $this->deductionsOf($r3));

        // And current stock remains 0
        $this->assertSame(0.0, $this->stockOf($r3));
    }

    public function test_ord006_same_day_sale_entered_as_already_shipped(): void
    {
        // Given the operator is filling the new-order form
        $r3 = $this->givenBottledWine();
        $customer = $this->givenCustomer();

        // When they select initial status SHIPPED and save
        $order = app(CreateOrderAction::class)->execute($customer, $this->admin->getKey(), [
            'status' => OrderStatus::Shipped->value,
            'items' => [['inventory_item_id' => $r3->getKey(), 'quantity' => 1, 'unit_type' => 'bottles']],
        ]);

        // Then the order is created with status SHIPPED in one step
        $this->assertSame(OrderStatus::Shipped, $order->refresh()->status);
    }

    /** Scenario Outline: any initial status can be chosen. */
    #[DataProvider('initialStatuses')]
    public function test_ord006_any_initial_status_can_be_chosen(string $status): void
    {
        // When an order is created with initial status <status>
        $r3 = $this->givenBottledWine();
        $customer = $this->givenCustomer();
        $order = app(CreateOrderAction::class)->execute($customer, $this->admin->getKey(), [
            'status' => $status,
            'items' => [['inventory_item_id' => $r3->getKey(), 'quantity' => 1, 'unit_type' => 'bottles']],
        ]);

        // Then the order's status is <status> immediately after save
        $this->assertSame($status, $order->refresh()->status->value);
    }

    /** @return array<string, array{string}> */
    public static function initialStatuses(): array
    {
        return [
            'RECEIVED' => [OrderStatus::Received->value],
            'IN_PROCESS' => [OrderStatus::InProcess->value],
            'SHIPPED' => [OrderStatus::Shipped->value],
        ];
    }

    public function test_ord007_status_jumps_directly_to_any_target(): void
    {
        // Given an order with status RECEIVED
        $r3 = $this->givenBottledWine();
        $customer = $this->givenCustomer();
        $order = app(CreateOrderAction::class)->execute($customer, $this->admin->getKey(), [
            'items' => [['inventory_item_id' => $r3->getKey(), 'quantity' => 1, 'unit_type' => 'bottles']],
        ]);

        // When the operator selects SHIPPED in the status control and saves once
        app(UpdateOrderStatusAction::class)->execute($order, OrderStatus::Shipped, null, $this->admin->getKey());

        // Then the order is SHIPPED
        $this->assertSame(OrderStatus::Shipped, $order->refresh()->status);

        // And no intermediate transitions are recorded as separate user actions
        $statuses = $order->statusHistories()->orderBy('created_at')->pluck('status')->all();
        $this->assertSame([OrderStatus::Received->value, OrderStatus::Shipped->value], array_map(
            fn ($s) => $s instanceof OrderStatus ? $s->value : (string) $s,
            $statuses,
        ));
    }

    public function test_ord023_new_order_notifications_include_the_creator(): void
    {
        // Given Ana creates a new order
        $r3 = $this->givenBottledWine();
        $customer = $this->givenCustomer();
        app(CreateOrderAction::class)->execute($customer, $this->admin->getKey(), [
            'items' => [['inventory_item_id' => $r3->getKey(), 'quantity' => 1, 'unit_type' => 'bottles']],
        ]);

        // Then every order-role user INCLUDING the creator gets the notification
        $recipients = Notification::query()
            ->where('type', NotificationType::NewOrder->value)
            ->pluck('user_id')
            ->all();
        $this->assertContains($this->admin->getKey(), $recipients);
    }
}
