<?php

declare(strict_types=1);

namespace Tests\Feature\Essentials;

use App\Actions\Orders\AddOrderItemsAction;
use App\Actions\Orders\CreateOrderAction;
use App\Actions\Orders\DeleteOrderAction;
use App\Actions\Orders\UpdateOrderItemAction;
use App\Authorization\MembershipContext;
use App\Enums\TenantRole;
use App\Exceptions\InsufficientStockException;
use App\Models\Order;
use App\Queries\InventorySpendQuery;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Feature: Orders — editing (ORD-008 … ORD-013).
 *
 * Migrated-contract notes:
 * - ORD-009/010 (the per-line ⇄ unit toggle): the rebuild made the sales unit
 *   STRICT per item — a cases item cannot be ordered in bottles on a staff
 *   line; the single-bottle exception moved to the portal's per-customer
 *   allow_single_bottle flag. The strictness itself is the contract here.
 * - ORD-008's exit correction: edits re-apply stock (restore + deduct), so the
 *   ledger always reflects the live quantity — reports need no read-time
 *   reconciliation; the corrected exit number is asserted via the spend query.
 */
class OrdersEditingTest extends EssentialsScenarioTestCase
{
    /** @return array<string, mixed> */
    private function spendSummary(): array
    {
        return app(InventorySpendQuery::class)->get(
            Carbon::now()->subDay(),
            Carbon::now()->addMinute(),
        )['summary'];
    }

    public function test_ord008_order_reduced_from_10_to_5_cases_corrects_stock_and_exit(): void
    {
        // Given an order was created for 10 cases (ORDER_DEDUCT -10 recorded)
        $shiraz = $this->givenCasedWine(['current_stock' => '50.000']);
        $customer = $this->givenCustomer();
        $order = app(CreateOrderAction::class)->execute($customer, $this->admin->getKey(), [
            'items' => [['inventory_item_id' => $shiraz->getKey(), 'quantity' => 10, 'unit_type' => 'cases']],
        ]);
        $this->assertSame(40.0, $this->stockOf($shiraz));

        // When the line quantity is edited to 5 cases
        $line = $order->items()->firstOrFail();
        app(UpdateOrderItemAction::class)->execute($line, 5, null);

        // Then stock increases by 5 cases (compensating restore + re-deduct)
        $this->assertSame(45.0, $this->stockOf($shiraz));

        // And the Inventory Exit report shows 5 cases for this order, not 10
        // (units are bottle-equivalents: 5 cases × 6 = 30)
        $this->assertSame(30, $this->spendSummary()['units_exited']);

        // And revenue for the order reflects 5 cases
        $this->assertSame(5, $order->items()->firstOrFail()->quantity);
    }

    public function test_ord009_strict_sales_unit_replaces_the_per_line_toggle(): void
    {
        // Given "Sangreal Shiraz 2021" sells in cases of 6
        $shiraz = $this->givenCasedWine();
        $customer = $this->givenCustomer();

        // When the operator tries to order 3 bottles of the cases-only product
        $error = null;
        try {
            app(CreateOrderAction::class)->execute($customer, $this->admin->getKey(), [
                'items' => [['inventory_item_id' => $shiraz->getKey(), 'quantity' => 3, 'unit_type' => 'bottles']],
            ]);
        } catch (ValidationException $e) {
            $error = $e->getMessage();
        }

        // Then the line is rejected with a clear unit message (the migrated
        // contract: strict sales units; single-bottle exceptions live on the
        // customer's portal flag, not ad-hoc per line)
        $this->assertNotNull($error);
        $this->assertStringContainsString('sold in cases', $error);

        // And a case line prices at per-bottle × bottlesPerCase (€60 × 6 = €360)
        $order = app(CreateOrderAction::class)->execute($customer, $this->admin->getKey(), [
            'items' => [['inventory_item_id' => $shiraz->getKey(), 'quantity' => 2, 'unit_type' => 'cases']],
        ]);
        $line = $order->items()->firstOrFail();
        $this->assertSame(36000, $line->unit_price->getMinorAmount()); // €360.00/case
        $this->assertSame(72000, $line->total->getMinorAmount());      // €720.00
    }

    public function test_ord011_adding_items_to_an_existing_order_behaves_like_creation(): void
    {
        // Given an existing order and "R3 2025" with 30 bottles in stock
        $r3 = $this->givenBottledWine(['current_stock' => '30.000']);
        $other = $this->givenBottledWine(['name' => 'Other', 'current_stock' => '100.000']);
        $customer = $this->givenCustomer();
        $order = app(CreateOrderAction::class)->execute($customer, $this->admin->getKey(), [
            'items' => [['inventory_item_id' => $other->getKey(), 'quantity' => 1, 'unit_type' => 'bottles']],
        ]);

        // When 12 bottles of "R3 2025" are added to the order
        app(AddOrderItemsAction::class)->execute($order, [
            ['inventory_item_id' => $r3->getKey(), 'quantity' => 12, 'unit_type' => 'bottles'],
        ]);

        // Then an ORDER_DEDUCT of -12 is recorded and stock becomes 18
        $deducts = $this->deductionsOf($r3);
        $this->assertCount(1, $deducts);
        $this->assertSame(-12.0, (float) $deducts[0]->quantity);
        $this->assertSame(18.0, $this->stockOf($r3));

        // And the overdraw guard (ORD-002) applies identically
        $this->expectException(InsufficientStockException::class);
        app(AddOrderItemsAction::class)->execute($order, [
            ['inventory_item_id' => $r3->getKey(), 'quantity' => 24, 'unit_type' => 'bottles'],
        ]);
    }

    public function test_ord012_deleting_an_order_restores_stock(): void
    {
        // Given an order deducted 24 bottles of "R3 2025"
        $r3 = $this->givenBottledWine(['current_stock' => '100.000']);
        $customer = $this->givenCustomer();
        $order = app(CreateOrderAction::class)->execute($customer, $this->admin->getKey(), [
            'items' => [['inventory_item_id' => $r3->getKey(), 'quantity' => 24, 'unit_type' => 'bottles']],
        ]);
        $this->assertSame(76.0, $this->stockOf($r3));
        $orderId = $order->getKey();

        // When the order is deleted
        app(DeleteOrderAction::class)->execute($order);

        // Then a compensating positive movement is recorded and stock is back to 100
        $this->assertSame(100.0, $this->stockOf($r3));
        $movements = $this->movementsOf($r3);
        $this->assertNotEmpty($movements);
        $restore = $movements[count($movements) - 1];
        $this->assertSame(24.0, (float) $restore->quantity);
        $this->assertStringContainsString(':deleted', (string) $restore->reference);
        $this->assertNull(Order::query()->find($orderId));
    }

    public function test_ord012_deleted_orders_vanish_from_exit_reports(): void
    {
        // Given a deleted order that contained bottles
        $r3 = $this->givenBottledWine(['current_stock' => '100.000']);
        $customer = $this->givenCustomer();
        $order = app(CreateOrderAction::class)->execute($customer, $this->admin->getKey(), [
            'items' => [['inventory_item_id' => $r3->getKey(), 'quantity' => 24, 'unit_type' => 'bottles']],
        ]);
        $this->assertSame(24, $this->spendSummary()['units_exited']);

        // When the order is deleted
        app(DeleteOrderAction::class)->execute($order);

        // Then those bottles contribute nothing to exits (INV-006: the stale
        // deduct is dropped at read time — its stock was already restored)
        $this->assertSame(100.0, $this->stockOf($r3));
        $this->assertSame(0, $this->spendSummary()['units_exited']);
        $this->assertSame(0, (int) ($this->spendSummary()['revenue']['minor'] ?? 0));
    }

    public function test_ord013_order_editing_is_an_explicit_per_user_flag(): void
    {
        // Given user Marko has role ORDERS and can_edit_orders = false,
        // and an order older than the 1-hour grace window
        $r3 = $this->givenBottledWine();
        $customer = $this->givenCustomer();
        $order = app(CreateOrderAction::class)->execute($customer, $this->admin->getKey(), [
            'items' => [['inventory_item_id' => $r3->getKey(), 'quantity' => 10, 'unit_type' => 'bottles']],
        ]);
        Order::query()->whereKey($order->getKey())->update(['created_at' => now()->subHours(2)]);
        $order->refresh();

        $marko = $this->createMember($this->tenant, [TenantRole::Orders]);
        $membership = $marko->membershipFor($this->tenant) ?? self::fail('Marko has no membership');
        app(MembershipContext::class)->set($membership);

        // When Marko tries to edit, the edit is refused
        $line = $order->items()->firstOrFail();
        $denied = null;
        try {
            app(UpdateOrderItemAction::class)->execute($line, 5, null);
        } catch (HttpException $e) {
            $denied = $e;
        }
        $this->assertNotNull($denied);
        $this->assertSame(403, $denied->getStatusCode());
        $this->assertStringContainsString('no longer be edited', (string) $denied->getMessage());

        // When the admin grants Marko can_edit_orders = true
        $membership->update(['can_edit_orders' => true]);
        app(MembershipContext::class)->set($membership->refresh());

        // Then Marko can edit orders without any role change
        $updated = app(UpdateOrderItemAction::class)->execute($line->refresh(), 5, null);
        $this->assertSame(5, $updated->quantity);
    }
}
