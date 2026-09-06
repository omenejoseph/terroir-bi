<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Actions\Orders\CreateOrderAction;
use App\Actions\Orders\DeleteOrderAction;
use App\Actions\Orders\DeleteOrderItemAction;
use App\Actions\Orders\UpdateOrderItemAction;
use App\Enums\TenantRole;
use App\Models\Customer;
use App\Models\InventoryItem;
use App\Models\StockMovement;
use App\Models\Tenant;
use App\Models\User;
use App\Queries\OrderStockReconciliationQuery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\InteractsWithTenancy;
use Tests\TestCase;

/**
 * "Check order → stock link" (Inventory Spend) — App\Queries\
 * OrderStockReconciliationQuery. Every scenario here is one of the two
 * failure modes the query's own docblock names: a recorded deduct that no
 * longer matches the live order (edited/removed/deleted), or a live order
 * line with no matching deduct at all.
 */
class OrderStockReconciliationTest extends TestCase
{
    use InteractsWithTenancy;
    use RefreshDatabase;

    private Tenant $tenant;

    private User $admin;

    private Customer $customer;

    private InventoryItem $wine;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-06-10 12:00:00');

        $this->tenant = $this->createTenant();
        $this->admin = $this->createMember($this->tenant, [TenantRole::Admin]);

        $this->actingAsTenant($this->tenant);
        $this->customer = Customer::create(['company_name' => 'Konoba Riva', 'email' => 'riva@example.com']);
        $this->wine = InventoryItem::create([
            'name' => 'Malvazija', 'sku' => 'MLV-2025', 'category' => 'FINISHED',
            'unit' => 'bottles', 'sales_unit' => 'bottles', 'current_stock' => '500.000',
            'is_for_sale' => true, 'default_price' => 1200,
        ]);
        $this->forgetTenant();

        $this->actingAs($this->admin);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @return array{0: Carbon, 1: Carbon} today ± a day, so anything created "now" is inside it. */
    private function window(): array
    {
        return [Carbon::now()->subDay(), Carbon::now()->addDay()];
    }

    /** @return list<array<string, mixed>> */
    private function reconciliationRows(): array
    {
        [$from, $to] = $this->window();

        $this->actingAsTenant($this->tenant);
        $rows = app(OrderStockReconciliationQuery::class)->get($from, $to);
        $this->forgetTenant();

        return $rows;
    }

    public function test_an_untouched_order_is_not_a_mismatch(): void
    {
        $this->actingAsTenant($this->tenant);
        app(CreateOrderAction::class)->execute($this->customer, $this->admin->getKey(), [
            'items' => [['inventory_item_id' => $this->wine->getKey(), 'quantity' => 24, 'unit_type' => 'bottles']],
        ]);
        $this->forgetTenant();

        self::assertSame([], $this->reconciliationRows());
    }

    public function test_an_order_edited_down_after_the_deduct_is_a_mismatch(): void
    {
        $this->actingAsTenant($this->tenant);
        $order = app(CreateOrderAction::class)->execute($this->customer, $this->admin->getKey(), [
            'items' => [['inventory_item_id' => $this->wine->getKey(), 'quantity' => 24, 'unit_type' => 'bottles']],
        ]);
        $line = $order->items()->firstOrFail();
        app(UpdateOrderItemAction::class)->execute($line, 10, 'bottles');
        $this->forgetTenant();

        // UpdateOrderItemAction restores the old 24 (a ManualIn — not an
        // ORDER_DEDUCT) then writes a fresh deduct of the new 10: the ledger's
        // ORDER_DEDUCT total for this pair is genuinely 24 + 10 = 34, not 24 —
        // both are real historical deduct events against this reference.
        $rows = $this->reconciliationRows();
        self::assertCount(1, $rows);
        self::assertSame($order->order_number, $rows[0]['order_number']);
        self::assertSame($order->getKey(), $rows[0]['order_id']);
        self::assertSame('Konoba Riva', $rows[0]['customer_name']);
        self::assertSame(34.0, $rows[0]['recorded_bottles']);
        self::assertSame(10.0, $rows[0]['current_bottles']);
        self::assertSame(-24.0, $rows[0]['delta']);
    }

    public function test_a_deleted_order_is_a_mismatch_with_no_order_context(): void
    {
        $this->actingAsTenant($this->tenant);
        $order = app(CreateOrderAction::class)->execute($this->customer, $this->admin->getKey(), [
            'items' => [['inventory_item_id' => $this->wine->getKey(), 'quantity' => 24, 'unit_type' => 'bottles']],
        ]);
        $orderNumber = $order->order_number;
        app(DeleteOrderAction::class)->execute($order);
        $this->forgetTenant();

        $rows = $this->reconciliationRows();
        self::assertCount(1, $rows);
        self::assertSame($orderNumber, $rows[0]['order_number']);
        self::assertNull($rows[0]['order_id']);
        self::assertNull($rows[0]['customer_name']);
        self::assertSame(24.0, $rows[0]['recorded_bottles']);
        self::assertSame(0.0, $rows[0]['current_bottles']);
        self::assertSame(-24.0, $rows[0]['delta']);
    }

    public function test_a_removed_line_is_a_mismatch_while_the_rest_of_the_order_survives(): void
    {
        $this->actingAsTenant($this->tenant);
        $other = InventoryItem::create([
            'name' => 'Label', 'sku' => 'LBL-1', 'category' => 'RAW_MATERIAL', 'unit' => 'units',
            'current_stock' => '1000',
        ]);
        $order = app(CreateOrderAction::class)->execute($this->customer, $this->admin->getKey(), [
            'items' => [
                ['inventory_item_id' => $this->wine->getKey(), 'quantity' => 12, 'unit_type' => 'bottles'],
                ['inventory_item_id' => $other->getKey(), 'quantity' => 5, 'unit_type' => 'bottles'],
            ],
        ]);
        $wineLine = $order->items()->where('inventory_item_id', $this->wine->getKey())->firstOrFail();
        app(DeleteOrderItemAction::class)->execute($wineLine);
        $this->forgetTenant();

        $rows = $this->reconciliationRows();
        self::assertCount(1, $rows);
        self::assertSame($this->wine->getKey(), $rows[0]['item_id']);
        self::assertSame(12.0, $rows[0]['recorded_bottles']);
        self::assertSame(0.0, $rows[0]['current_bottles']);
    }

    public function test_a_backorder_that_never_deducted_is_not_a_mismatch(): void
    {
        $this->actingAsTenant($this->tenant);
        app(CreateOrderAction::class)->execute($this->customer, $this->admin->getKey(), [
            'is_backorder' => true,
            'items' => [['inventory_item_id' => $this->wine->getKey(), 'quantity' => 24, 'unit_type' => 'bottles']],
        ]);
        $this->forgetTenant();

        self::assertSame([], $this->reconciliationRows());
    }

    public function test_a_line_that_should_have_deducted_but_has_no_movement_is_a_mismatch(): void
    {
        // Simulates the exact failure the page's "sitting untouched" callout
        // warns about: an order that should have moved stock, but the
        // movement never reached the ledger.
        $this->actingAsTenant($this->tenant);
        $order = app(CreateOrderAction::class)->execute($this->customer, $this->admin->getKey(), [
            'items' => [['inventory_item_id' => $this->wine->getKey(), 'quantity' => 24, 'unit_type' => 'bottles']],
        ]);
        StockMovement::query()->where('inventory_item_id', $this->wine->getKey())->delete();
        $this->forgetTenant();

        $rows = $this->reconciliationRows();
        self::assertCount(1, $rows);
        self::assertSame($order->order_number, $rows[0]['order_number']);
        self::assertSame(0.0, $rows[0]['recorded_bottles']);
        self::assertSame(24.0, $rows[0]['current_bottles']);
        self::assertSame(24.0, $rows[0]['delta']);
    }

    public function test_orders_outside_the_window_are_not_reported(): void
    {
        $this->actingAsTenant($this->tenant);
        app(CreateOrderAction::class)->execute($this->customer, $this->admin->getKey(), [
            'items' => [['inventory_item_id' => $this->wine->getKey(), 'quantity' => 24, 'unit_type' => 'bottles']],
        ]);
        $this->forgetTenant();

        $this->actingAsTenant($this->tenant);
        $rows = app(OrderStockReconciliationQuery::class)->get(
            Carbon::now()->addDays(10),
            Carbon::now()->addDays(20),
        );
        $this->forgetTenant();

        self::assertSame([], $rows);
    }
}
