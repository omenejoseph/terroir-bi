<?php

declare(strict_types=1);

namespace Tests\Feature\Essentials;

use App\Actions\Inventory\ApplyInventoryCheckAction;
use App\Actions\Orders\CreateOrderAction;
use App\Enums\StockMovementType;
use App\Models\StockMovement;
use App\Queries\InventorySpendQuery;
use App\Services\Inventory\StockLedger;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Feature: Inventory — movement semantics, the ledger & exit identity
 * (INV-001 … INV-004, INV-019).
 */
class InventoryLedgerTest extends EssentialsScenarioTestCase
{
    /** @return array<string, mixed> */
    private function spendSummary(): array
    {
        return app(InventorySpendQuery::class)->get(
            Carbon::now()->subDay(),
            Carbon::now()->addMinute(),
        )['summary'];
    }

    public function test_inv001_order_deduct_counts_toward_exit(): void
    {
        // Given a stock movement of type ORDER_DEDUCT with negative quantity
        $r3 = $this->givenBottledWine();
        $customer = $this->givenCustomer();
        app(CreateOrderAction::class)->execute($customer, $this->admin->getKey(), [
            'items' => [['inventory_item_id' => $r3->getKey(), 'quantity' => 24, 'unit_type' => 'bottles']],
        ]);

        // Then it counts toward Inventory Exit (validated against the live order)
        $this->assertSame(24, $this->spendSummary()['units_exited']);
    }

    public function test_inv001_manual_out_counts_unless_flagged_reconciliation(): void
    {
        // Given a MANUAL_OUT of 10 (samples)
        $r3 = $this->givenBottledWine();
        app(StockLedger::class)->record($r3, StockMovementType::ManualOut, '-10', note: 'samples');

        // Then it counts toward Inventory Exit
        $this->assertSame(10, $this->spendSummary()['units_exited']);
    }

    public function test_inv001_adjustment_never_counts(): void
    {
        // Given an ADJUSTMENT with negative quantity
        $r3 = $this->givenBottledWine();
        app(StockLedger::class)->record($r3, StockMovementType::Adjustment, '-10', note: 'count correction');

        // Then it never counts toward Inventory Exit
        $this->assertSame(0, $this->spendSummary()['units_exited']);
        // But stock did decrease (the book was corrected)
        $this->assertSame(90.0, $this->stockOf($r3));
    }

    public function test_inv002_reconciliation_flag_excludes_a_manual_out_from_exits(): void
    {
        // Given a physical count found 50 fewer bottles than the book
        $r3 = $this->givenBottledWine(['current_stock' => '200.000']);

        // When the operator enters MANUAL_OUT -50 ticked as a count correction
        app(StockLedger::class)->record($r3, StockMovementType::ManualOut, '-50', note: 'recount', isReconciliation: true);

        // Then stock decreases by 50
        $this->assertSame(150.0, $this->stockOf($r3));

        // And the movement is excluded from every exit metric
        $this->assertSame(0, $this->spendSummary()['units_exited']);
    }

    public function test_inv003_exit_identity_produced_equals_on_hand_plus_genuine_exits(): void
    {
        // Given 931 bottles of "R3 2025" produced (scaled-down sanity check)
        $r3 = $this->givenBottledWine(['current_stock' => '931.000']);
        $customer = $this->givenCustomer();

        // Genuine exits: a 200-bottle order + 30 sample bottles
        app(CreateOrderAction::class)->execute($customer, $this->admin->getKey(), [
            'items' => [['inventory_item_id' => $r3->getKey(), 'quantity' => 200, 'unit_type' => 'bottles']],
        ]);
        app(StockLedger::class)->record($r3->refresh(), StockMovementType::ManualOut, '-30', note: 'samples');
        // Noise that must NOT count: a flagged recount and an adjustment
        app(StockLedger::class)->record($r3->refresh(), StockMovementType::ManualOut, '-64', note: 'recount', isReconciliation: true);
        app(StockLedger::class)->record($r3->refresh(), StockMovementType::Adjustment, '-100', note: 'count correction');

        // Then genuine exits ≤ produced − on-hand, and the inflated figure is impossible
        $exits = $this->spendSummary()['units_exited'];
        $this->assertSame(230, $exits); // 200 + 30, never 394
        $this->assertLessThanOrEqual(931 - $this->stockOf($r3), (float) $exits);
    }

    /**
     * Scenario Outline: an order line converts to the product's storage unit.
     *
     * @param  'bottles'|'cases'  $storage
     */
    #[DataProvider('unitConversions')]
    public function test_inv004_order_line_converts_to_the_storage_unit(
        string $storage,
        string $lineUnit,
        int $qty,
        float $expectedMovement,
    ): void {
        // Given a product stored in <storage> with 6 bottles per case, whose
        // sales unit matches the line (the migrated app sells in one unit)
        $item = $storage === 'cases'
            ? $this->givenCasedWine(['sales_unit' => $lineUnit])
            : $this->givenBottledWine(['sales_unit' => $lineUnit]);
        $customer = $this->givenCustomer();

        // When an order line of <qty> <lineUnit> is deducted
        app(CreateOrderAction::class)->execute($customer, $this->admin->getKey(), [
            'items' => [['inventory_item_id' => $item->getKey(), 'quantity' => $qty, 'unit_type' => $lineUnit]],
        ]);

        // Then the movement records <result> in the storage unit
        $deducts = $this->deductionsOf($item);
        $this->assertCount(1, $deducts);
        $this->assertSame(-$expectedMovement, (float) $deducts[0]->quantity);
        $this->assertSame($storage, $deducts[0]->unit);
    }

    /** @return array<string, array{string, string, int, float}> */
    public static function unitConversions(): array
    {
        return [
            'cases stored, bottles line' => ['cases', 'bottles', 3, 0.5],
            'cases stored, cases line' => ['cases', 'cases', 2, 2.0],
            'bottles stored, cases line' => ['bottles', 'cases', 2, 12.0],
            'bottles stored, bottles line' => ['bottles', 'bottles', 5, 5.0],
        ];
    }

    public function test_inv004_each_movement_converts_by_its_own_recorded_unit(): void
    {
        // Given historical movements with mixed units on a bottles-stored product
        $item = $this->givenBottledWine(['bottles_per_case' => 6]);
        // One recorded in cases (e.g. written before the item switched units)
        StockMovement::create([
            'inventory_item_id' => $item->getKey(),
            'type' => StockMovementType::ManualOut,
            'quantity' => -2,
            'unit' => 'cases',
        ]);
        // And one recorded in bottles
        StockMovement::create([
            'inventory_item_id' => $item->getKey(),
            'type' => StockMovementType::ManualOut,
            'quantity' => -5,
            'unit' => 'bottles',
        ]);

        // Then aggregation uses each movement's unit: 2×6 + 5 = 17 bottles,
        // never a blanket ×bottles_per_case (which would claim 42)
        $this->assertSame(17, $this->spendSummary()['units_exited']);
    }

    public function test_inv019_physical_count_reconciles_in_bulk_without_touching_exits(): void
    {
        // Given the operator submits counted quantities for several products
        $a = $this->givenBottledWine(['name' => 'Wine A', 'current_stock' => '100.000']);
        $b = $this->givenBottledWine(['name' => 'Wine B', 'current_stock' => '50.000']);
        $c = $this->givenBottledWine(['name' => 'Wine C', 'current_stock' => '80.000']);

        app(ApplyInventoryCheckAction::class)->execute([
            ['item_id' => $a->getKey(), 'physical_count' => '93'],
            ['item_id' => $b->getKey(), 'physical_count' => '55'],
            ['item_id' => $c->getKey(), 'physical_count' => '80'], // unchanged
        ], $this->admin);

        // Then correction movements are written for each difference, flagged
        $aMoves = $this->movementsOf($a);
        $this->assertCount(1, $aMoves);
        $this->assertSame(StockMovementType::Adjustment, $aMoves[0]->type);
        $this->assertSame(-7.0, (float) $aMoves[0]->quantity);
        $this->assertTrue($aMoves[0]->is_reconciliation);
        $this->assertCount(1, $this->movementsOf($b));
        $this->assertCount(0, $this->movementsOf($c));

        // And book stock equals counted stock afterwards
        $this->assertSame(93.0, $this->stockOf($a));
        $this->assertSame(55.0, $this->stockOf($b));

        // And exit metrics are unaffected by the corrections
        $this->assertSame(0, $this->spendSummary()['units_exited']);
    }
}
