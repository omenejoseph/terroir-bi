<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Enums\StockMovementType;
use App\Enums\TenantRole;
use App\Models\InventoryItem;
use App\Models\Tenant;
use App\Queries\VintageCoverageQuery;
use App\Services\Inventory\StockLedger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\InteractsWithTenancy;
use Tests\TestCase;

/**
 * Locks the vintage-transition maths: per-vintage runway (90-day velocity →
 * days of stock), blended combined coverage, and the FIFO transition read.
 */
class VintageCoverageTest extends TestCase
{
    use InteractsWithTenancy;
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-06-15 12:00:00');
        $this->tenant = $this->createTenant();
        $this->createMember($this->tenant, [TenantRole::Admin]);
        $this->actingAsTenant($this->tenant);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function wine(string $vintage): InventoryItem
    {
        return InventoryItem::create([
            'name' => 'R3', 'sku' => "R3-{$vintage}", 'category' => 'FINISHED', 'vintage' => $vintage,
            'unit' => 'bottles', 'sales_unit' => 'bottles', 'bottles_per_case' => 6,
            'is_for_sale' => true, 'is_active' => true,
        ]);
    }

    public function test_multi_vintage_coverage_numbers(): void
    {
        $ledger = app(StockLedger::class);

        // Current 2025: opening 5400 − 1800 exit = 3600 in stock; velocity 1800/90 = 20/day → 180d.
        $current = $this->wine('2025');
        $ledger->record($current, StockMovementType::ManualIn, '5400', 'open');
        $ledger->record($current, StockMovementType::ManualOut, '-1800', 'sale');

        // Prior 2024: opening 990 − 90 exit = 900 in stock; velocity 90/90 = 1/day → 900d.
        $prior = $this->wine('2024');
        $ledger->record($prior, StockMovementType::ManualIn, '990', 'open');
        $ledger->record($prior, StockMovementType::ManualOut, '-90', 'sale');

        $d = app(VintageCoverageQuery::class)->get($current);
        $this->assertNotNull($d);

        // Newest vintage first.
        $this->assertCount(2, $d['vintages']);
        [$v2025, $v2024] = $d['vintages'];

        $this->assertSame('2025', $v2025['vintage']);
        $this->assertSame(3600, $v2025['stock_bottles']);
        $this->assertSame(20.0, $v2025['velocity_per_day']);
        $this->assertSame(180.0, $v2025['days_left']);
        $this->assertTrue($v2025['is_current']);

        $this->assertSame('2024', $v2024['vintage']);
        $this->assertSame(900, $v2024['stock_bottles']);
        $this->assertSame(1.0, $v2024['velocity_per_day']);
        $this->assertSame(900.0, $v2024['days_left']);
        $this->assertFalse($v2024['is_current']);

        // Blended: 4500 bottles ÷ 21/day = 214.3d.
        $this->assertSame(4500, $d['total_stock_bottles']);
        $this->assertSame(21.0, $d['blended_velocity_per_day']);
        $this->assertSame(214.3, $d['combined_days_left']);

        // FIFO: oldest in-stock (2024) sells down, 2025 is the successor.
        $this->assertSame('2024', $d['selling_vintage']);
        $this->assertSame('2025', $d['successor_vintage']);
        $this->assertFalse($d['gap_risk']);
        $this->assertSame(90, $d['window_days']);
    }

    public function test_single_vintage_returns_null(): void
    {
        $solo = $this->wine('2025');
        app(StockLedger::class)->record($solo, StockMovementType::ManualIn, '500', 'open');

        $this->assertNull(app(VintageCoverageQuery::class)->get($solo));
    }
}
