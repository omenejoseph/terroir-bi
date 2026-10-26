<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\InteractsWithTenancy;
use Tests\TestCase;

/** The migration that rounds fractional stock of bottles / cases / units to whole pieces. */
class WholeUnitStockFixTest extends TestCase
{
    use InteractsWithTenancy;
    use RefreshDatabase;

    private function item(string $unit, string $stock, ?string $min = null): string
    {
        $tenant = $this->createTenant();
        $id = (string) Str::ulid();

        DB::table('inventory_items')->insert([
            'id' => $id, 'tenant_id' => $tenant->getKey(), 'name' => 'R3 '.$id, 'sku' => $id,
            'category' => 'FINISHED', 'unit' => $unit, 'current_stock' => $stock, 'min_stock' => $min,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $id;
    }

    private function migrate(): void
    {
        (require base_path('database/migrations/2026_10_22_000001_round_fractional_stock_of_whole_unit_items.php'))->up();
    }

    public function test_fractional_stock_of_whole_unit_items_is_rounded_with_a_ledger_entry(): void
    {
        $bottles = $this->item('bottles', '1.300', '2.500');
        $cases = $this->item('Cases', '4.700');

        $this->migrate();

        $this->assertSame('1.000', number_format((float) DB::table('inventory_items')->where('id', $bottles)->value('current_stock'), 3, '.', ''));
        $this->assertSame('5.000', number_format((float) DB::table('inventory_items')->where('id', $cases)->value('current_stock'), 3, '.', ''));
        $this->assertSame(3.0, (float) DB::table('inventory_items')->where('id', $bottles)->value('min_stock'));

        $movement = DB::table('stock_movements')->where('inventory_item_id', $bottles)->first();
        assert($movement !== null);
        $this->assertSame('ADJUSTMENT', $movement->type);
        $this->assertEqualsWithDelta(-0.3, (float) $movement->quantity, 0.0001);
        $this->assertTrue((bool) $movement->is_reconciliation);
        $this->assertEqualsWithDelta(0.3, (float) DB::table('stock_movements')->where('inventory_item_id', $cases)->value('quantity'), 0.0001);
    }

    public function test_measured_units_and_whole_stock_are_left_alone_and_it_is_repeatable(): void
    {
        $litres = $this->item('liters', '12.345');
        $whole = $this->item('bottles', '7.000');

        $this->migrate();
        $this->migrate();

        $this->assertEqualsWithDelta(12.345, (float) DB::table('inventory_items')->where('id', $litres)->value('current_stock'), 0.0001);
        $this->assertSame(0, DB::table('stock_movements')->whereIn('inventory_item_id', [$litres, $whole])->count());
    }

    public function test_running_it_twice_writes_one_movement(): void
    {
        $bottles = $this->item('bottles', '1.300');

        $this->migrate();
        $this->migrate();

        $this->assertSame(1, DB::table('stock_movements')->where('inventory_item_id', $bottles)->count());
    }
}
