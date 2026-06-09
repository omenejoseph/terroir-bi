<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Enums\StockMovementType;
use App\Exceptions\InsufficientStockException;
use App\Models\InventoryItem;
use App\Services\Inventory\StockLedger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithTenancy;
use Tests\TestCase;

class StockLedgerDeductTest extends TestCase
{
    use InteractsWithTenancy;
    use RefreshDatabase;

    private function ledger(): StockLedger
    {
        return app(StockLedger::class);
    }

    public function test_deduct_converts_cases_to_bottles_and_records_order_deduct(): void
    {
        $tenant = $this->createTenant();
        $this->actingAsTenant($tenant);
        $item = InventoryItem::create([
            'name' => 'Plavac', 'sku' => 'PLAVAC', 'category' => 'FINISHED',
            'unit' => 'bottles', 'current_stock' => '100.000', 'bottles_per_case' => 12,
        ]);

        $movement = $this->ledger()->deduct($item, '2', 'cases', reference: 'ORD-1');

        $this->assertSame(StockMovementType::OrderDeduct, $movement->type);
        $this->assertSame('-24.000', (string) $movement->quantity);
        $this->assertSame('76.000', (string) $item->refresh()->current_stock);
    }

    public function test_deduct_converts_bottles_to_a_case_stored_item(): void
    {
        $tenant = $this->createTenant();
        $this->actingAsTenant($tenant);
        $item = InventoryItem::create([
            'name' => 'Pošip', 'sku' => 'POSIP', 'category' => 'FINISHED',
            'unit' => 'cases', 'current_stock' => '10.000', 'bottles_per_case' => 12,
        ]);

        $this->ledger()->deduct($item, '12', 'bottles', reference: 'ORD-2');

        $this->assertSame('9.000', (string) $item->refresh()->current_stock);
    }

    public function test_deduct_refuses_to_drive_stock_negative(): void
    {
        $tenant = $this->createTenant();
        $this->actingAsTenant($tenant);
        $item = InventoryItem::create([
            'name' => 'Malvazija', 'sku' => 'MAL', 'category' => 'FINISHED',
            'unit' => 'bottles', 'current_stock' => '5.000', 'bottles_per_case' => 12,
        ]);

        try {
            $this->ledger()->deduct($item, '1', 'cases'); // needs 12, only 5
            $this->fail('Expected InsufficientStockException');
        } catch (InsufficientStockException $e) {
            $this->assertSame('5.000', $e->available);
            $this->assertSame('12.000', $e->needed);
        }

        // Stock untouched and no movement written.
        $this->assertSame('5.000', (string) $item->refresh()->current_stock);
        $this->assertSame(0, $item->stockMovements()->count());
    }

    public function test_restore_adds_stock_back(): void
    {
        $tenant = $this->createTenant();
        $this->actingAsTenant($tenant);
        $item = InventoryItem::create([
            'name' => 'Teran', 'sku' => 'TERAN', 'category' => 'FINISHED',
            'unit' => 'bottles', 'current_stock' => '0.000', 'bottles_per_case' => 6,
        ]);

        $this->ledger()->restore($item, '1', 'cases', reference: 'ORD-3:restock');

        $this->assertSame('6.000', (string) $item->refresh()->current_stock);
    }
}
