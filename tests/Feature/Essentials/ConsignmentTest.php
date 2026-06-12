<?php

declare(strict_types=1);

namespace Tests\Feature\Essentials;

use App\Actions\Orders\CloseConsignmentAction;
use App\Actions\Orders\CreateOrderAction;
use App\Actions\Orders\DeleteOrderAction;
use App\Actions\Orders\RecordConsignmentReturnAction;
use App\Actions\Orders\RecordConsignmentSaleAction;
use App\Models\Customer;
use App\Models\InventoryItem;
use App\Models\Order;
use App\Queries\InventorySpendQuery;
use App\Services\Orders\ConsignmentService;
use App\Services\Orders\CustomerConsignmentService;
use Illuminate\Support\Carbon;

/**
 * Feature: Consignment — komisijska prodaja (CON-001 … CON-005, CON-007).
 * Placement moves stock but earns nothing; SALE reports are the revenue
 * events; RETURN restores stock; close returns the remainder; the customer
 * tab allocates FIFO across placements.
 */
class ConsignmentTest extends EssentialsScenarioTestCase
{
    /**
     * Place a consignment order for a fresh "Craft Technology"-style partner.
     *
     * @return array{0: Order, 1: string} [order, line id]
     */
    private function givenPlacement(InventoryItem $item, int $qty, string $unitType, ?int $unitPrice = null): array
    {
        $customer = $this->givenCustomer(['company_name' => 'Craft Technology', 'rebate_percent' => 20]);

        return $this->placeFor($customer, $item, $qty, $unitType, $unitPrice);
    }

    /** @return array{0: Order, 1: string} */
    private function placeFor(Customer $customer, InventoryItem $item, int $qty, string $unitType, ?int $unitPrice = null): array
    {
        $line = ['inventory_item_id' => $item->getKey(), 'quantity' => $qty, 'unit_type' => $unitType];
        if ($unitPrice !== null) {
            $line['unit_price'] = $unitPrice;
        }
        $order = app(CreateOrderAction::class)->execute($customer, $this->admin->getKey(), [
            'is_consignment' => true,
            'items' => [$line],
        ]);

        return [$order, (string) $order->items()->firstOrFail()->getKey()];
    }

    public function test_con001_placement_moves_stock_but_earns_nothing(): void
    {
        // Given a consignment order placing 60 bottles of "R3 2025" at €8.00/bottle
        $r3 = $this->givenBottledWine(['current_stock' => '100.000']);
        [$order] = $this->givenPlacement($r3, 60, 'bottles', 800);

        // When the order is dispatched, stock decreases by 60 (physical exit)
        $this->assertSame(40.0, $this->stockOf($r3));

        // And revenue recognized is €0.00
        $tally = app(ConsignmentService::class)->tally($order);
        $revenue = array_sum(array_map(fn (array $t) => $t['revenue_minor'], $tally));
        $this->assertSame(0, $revenue);
    }

    public function test_con001_sale_report_is_the_revenue_event(): void
    {
        // Given 60 bottles placed at €8.00
        $r3 = $this->givenBottledWine(['current_stock' => '100.000']);
        [$order, $lineId] = $this->givenPlacement($r3, 60, 'bottles', 800);

        // When a SALE report records 20 bottles sold
        app(RecordConsignmentSaleAction::class)->execute($order, [
            ['order_item_id' => $lineId, 'quantity' => 20],
        ], null, $this->admin->getKey());

        // Then revenue of €160.00 is recognized
        $tally = app(ConsignmentService::class)->tally($order->refresh());
        $this->assertSame(16000, array_sum(array_map(fn (array $t) => $t['revenue_minor'], $tally)));

        // And the price defaults to the placement price but can be overridden per report
        app(RecordConsignmentSaleAction::class)->execute($order, [
            ['order_item_id' => $lineId, 'quantity' => 5, 'unit_price' => 1000],
        ], null, $this->admin->getKey());
        $tally = app(ConsignmentService::class)->tally($order->refresh());
        $this->assertSame(16000 + 5000, array_sum(array_map(fn (array $t) => $t['revenue_minor'], $tally)));
    }

    public function test_con001_return_restores_stock_without_revenue(): void
    {
        // Given 60 bottles placed
        $r3 = $this->givenBottledWine(['current_stock' => '100.000']);
        [$order, $lineId] = $this->givenPlacement($r3, 60, 'bottles', 800);
        $this->assertSame(40.0, $this->stockOf($r3));

        // When a RETURN report records 10 bottles
        app(RecordConsignmentReturnAction::class)->execute($order, [
            ['order_item_id' => $lineId, 'quantity' => 10],
        ], null, $this->admin->getKey());

        // Then stock increases by 10 and no revenue changes
        $this->assertSame(50.0, $this->stockOf($r3));
        $tally = app(ConsignmentService::class)->tally($order->refresh());
        $this->assertSame(0, array_sum(array_map(fn (array $t) => $t['revenue_minor'], $tally)));
    }

    public function test_con001_close_returns_the_remainder(): void
    {
        // Given 60 placed, 20 sold, 10 returned
        $r3 = $this->givenBottledWine(['current_stock' => '100.000']);
        [$order, $lineId] = $this->givenPlacement($r3, 60, 'bottles', 800);
        app(RecordConsignmentSaleAction::class)->execute($order, [['order_item_id' => $lineId, 'quantity' => 20]], null, $this->admin->getKey());
        app(RecordConsignmentReturnAction::class)->execute($order, [['order_item_id' => $lineId, 'quantity' => 10]], null, $this->admin->getKey());
        $this->assertSame(50.0, $this->stockOf($r3)); // 100 − 60 + 10

        // When the operator closes the order
        app(CloseConsignmentAction::class)->execute($order->refresh(), $this->admin->getKey());

        // Then the remaining 30 return to stock and the order is finished
        $this->assertSame(80.0, $this->stockOf($r3));
        $this->assertNotNull($order->refresh()->consignment_closed_at);
        // And revenue is untouched by the close (sales only: 20 × €8)
        $tally = app(ConsignmentService::class)->tally($order);
        $this->assertSame(16000, array_sum(array_map(fn (array $t) => $t['revenue_minor'], $tally)));
    }

    public function test_con002_placed_in_cases_sold_by_the_bottle(): void
    {
        // Given a placement of 5 cases (6 bottles each) at €360.00/case
        $shiraz = $this->givenCasedWine(['current_stock' => '10.000']);
        $customer = $this->givenCustomer();
        [$order, $lineId] = $this->placeFor($customer, $shiraz, 5, 'cases', 36000);
        $this->assertSame(5.0, $this->stockOf($shiraz));

        // Then the working ledger reconciles in single bottles: 30 placed @ €60
        $tally = app(ConsignmentService::class)->tally($order);
        $this->assertSame(30, $tally[$lineId]['placed']);

        // When recording a sale of 7 BOTTLES at the per-bottle price
        app(RecordConsignmentSaleAction::class)->execute($order, [
            ['order_item_id' => $lineId, 'quantity' => 7],
        ], null, $this->admin->getKey());
        $tally = app(ConsignmentService::class)->tally($order->refresh());
        $this->assertSame(7, $tally[$lineId]['sold']);
        $this->assertSame(7 * 6000, $tally[$lineId]['revenue_minor']); // €60/bottle

        // And returns convert back to cases automatically (7 bottles ≈ 1.1667 cases)
        app(RecordConsignmentReturnAction::class)->execute($order->refresh(), [
            ['order_item_id' => $lineId, 'quantity' => 7],
        ], null, $this->admin->getKey());
        $this->assertEqualsWithDelta(5 + 7 / 6, $this->stockOf($shiraz), 0.001);
    }

    public function test_con003_profitability_follows_sell_through_and_placements_stay_out_of_revenue(): void
    {
        // Given a consignment order with €5,000 placed and €1,200 reported sold
        $r3 = $this->givenBottledWine(['current_stock' => '1000.000', 'cost_per_unit' => 542]);
        $customer = $this->givenCustomer();
        [$order, $lineId] = $this->placeFor($customer, $r3, 625, 'bottles', 800); // €5,000 placed
        app(RecordConsignmentSaleAction::class)->execute($order, [
            ['order_item_id' => $lineId, 'quantity' => 150], // €1,200
        ], null, $this->admin->getKey());

        // Then the order's profitability shows revenue €1,200 (not €5,000)
        $tally = app(ConsignmentService::class)->tally($order->refresh());
        $this->assertSame(120000, $tally[$lineId]['revenue_minor']);

        // And (CON-007 inverse) the spend report's revenue EXCLUDES the placement
        $summary = app(InventorySpendQuery::class)->get(Carbon::now()->subDay(), Carbon::now()->addMinute())['summary'];
        $this->assertSame(0, (int) ($summary['revenue']['minor'] ?? 0));
        // while the physical exit of 625 bottles still counts
        $this->assertSame(625, $summary['units_exited']);
    }

    public function test_con004_deleting_a_consignment_order_restores_only_what_is_still_at_the_customer(): void
    {
        // Given a consignment order: 60 placed, 20 sold, 10 returned
        $r3 = $this->givenBottledWine(['current_stock' => '100.000']);
        [$order, $lineId] = $this->givenPlacement($r3, 60, 'bottles', 800);
        app(RecordConsignmentSaleAction::class)->execute($order, [['order_item_id' => $lineId, 'quantity' => 20]], null, $this->admin->getKey());
        app(RecordConsignmentReturnAction::class)->execute($order, [['order_item_id' => $lineId, 'quantity' => 10]], null, $this->admin->getKey());
        $this->assertSame(50.0, $this->stockOf($r3)); // 100 − 60 + 10

        // When the order is deleted
        app(DeleteOrderAction::class)->execute($order->refresh());

        // Then stock increases by exactly 30 (placed − sold − returned), NOT by 60
        $this->assertSame(80.0, $this->stockOf($r3));
    }

    public function test_con005_fifo_allocation_across_placements(): void
    {
        // Given "Craft Technology" has two open placements of "R3 2025":
        //   30 bottles @ €8.00 (January) and 30 bottles @ €8.50 (March)
        $r3 = $this->givenBottledWine(['current_stock' => '200.000']);
        $craft = $this->givenCustomer(['company_name' => 'Craft Technology']);
        [$jan] = $this->placeFor($craft, $r3, 30, 'bottles', 800);
        Order::query()->whereKey($jan->getKey())->update(['created_at' => Carbon::parse('2026-01-10')]);
        [$mar] = $this->placeFor($craft, $r3, 30, 'bottles', 850);
        Order::query()->whereKey($mar->getKey())->update(['created_at' => Carbon::parse('2026-03-10')]);

        // When a sale of 40 bottles is recorded from the Komisija tab
        app(CustomerConsignmentService::class)->sale($craft, [
            ['inventory_item_id' => $r3->getKey(), 'quantity' => 40],
        ], null, $this->admin->getKey());

        // Then 30 allocate to January at €8.00 and 10 to March at €8.50
        $janTally = app(ConsignmentService::class)->tally($jan->refresh());
        $marTally = app(ConsignmentService::class)->tally($mar->refresh());
        $this->assertSame(30, array_sum(array_map(fn (array $t) => $t['sold'], $janTally)));
        $this->assertSame(10, array_sum(array_map(fn (array $t) => $t['sold'], $marTally)));

        // And revenue is €325.00 (30×8.00 + 10×8.50)
        $revenue = array_sum(array_map(fn (array $t) => $t['revenue_minor'], $janTally))
            + array_sum(array_map(fn (array $t) => $t['revenue_minor'], $marTally));
        $this->assertSame(32500, $revenue);

        // And the tab is a working ledger: placed / sold / returned / remaining
        $summary = app(CustomerConsignmentService::class)->summary($craft);
        $this->assertSame(60, array_sum(array_map(fn (array $p) => (int) $p['placed'], $summary['products'])));
        $this->assertSame(40, array_sum(array_map(fn (array $p) => (int) $p['sold'], $summary['products'])));
        $this->assertSame(20, array_sum(array_map(fn (array $p) => (int) $p['remaining'], $summary['products'])));
    }
}
