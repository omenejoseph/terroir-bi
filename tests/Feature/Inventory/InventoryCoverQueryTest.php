<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Models\InventoryItem;
use App\Models\StockMovement;
use App\Models\Tenant;
use App\Queries\InventoryCoverQuery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\InteractsWithTenancy;
use Tests\TestCase;

/**
 * "Cover" for a page of items at once — the same days-of-stock-left math as
 * InventoryItemStockAnalyticsQuery::exits() (see
 * InventoryItemStockAnalyticsTest::test_current_and_realized_metrics for the
 * per-item version this mirrors), computed set-wise so the inventory list can
 * afford to show it on every row.
 */
class InventoryCoverQueryTest extends TestCase
{
    use InteractsWithTenancy;
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-06-15 12:00:00');
        $this->tenant = $this->createTenant();
        $this->actingAsTenant($this->tenant);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function exit(InventoryItem $item, int $qty, string $at): void
    {
        $m = StockMovement::create([
            'inventory_item_id' => $item->getKey(), 'type' => 'MANUAL_OUT', 'quantity' => $qty,
        ]);
        $m->forceFill(['created_at' => Carbon::parse($at)])->save();
    }

    public function test_days_left_matches_stock_over_exit_rate(): void
    {
        // Same fixture as InventoryItemStockAnalyticsTest: 150 in stock, 130
        // bottles exited across the trailing 30 days -> round(150×30/130) = 35.
        $item = InventoryItem::create([
            'name' => 'Plavac', 'sku' => 'PLV', 'category' => 'FINISHED', 'unit' => 'bottles',
            'current_stock' => '150.000',
        ]);
        $this->exit($item, -100, '2026-06-12');
        $this->exit($item, -20, '2026-06-12');
        $this->exit($item, -10, '2026-06-12');
        $this->exit($item, -500, '2026-03-01'); // ~106 days ago — outside the 30d window

        $cover = app(InventoryCoverQuery::class)->forItems([$item]);

        $this->assertSame(35, $cover[$item->getKey()]);
    }

    public function test_an_item_with_no_exits_in_the_window_is_null(): void
    {
        $item = InventoryItem::create([
            'name' => 'Untouched', 'sku' => 'UNT-1', 'category' => 'FINISHED', 'unit' => 'bottles',
            'current_stock' => '50',
        ]);

        $cover = app(InventoryCoverQuery::class)->forItems([$item]);

        $this->assertNull($cover[$item->getKey()]);
    }

    /**
     * Movements are recorded in the item's own unit — a case-unit item's exit
     * of "2" moved 2 cases, not 2 bottles — so both sides of the ratio must be
     * normalised to bottles the same way the per-item query does.
     */
    public function test_case_unit_items_are_normalised_to_bottles(): void
    {
        $item = InventoryItem::create([
            'name' => 'Boxed', 'sku' => 'BOX-1', 'category' => 'FINISHED', 'unit' => 'cases',
            'bottles_per_case' => 12, 'current_stock' => '10', // 120 bottles
        ]);
        $this->exit($item, -2, '2026-06-12'); // 2 cases = 24 bottles, in the last 30 days

        $cover = app(InventoryCoverQuery::class)->forItems([$item]);

        // round(120 bottles × 30 / 24 bottles) = 150
        $this->assertSame(150, $cover[$item->getKey()]);
    }

    /**
     * A single grouped query must not let one item's movements leak into
     * another's figure.
     */
    public function test_multiple_items_are_kept_separate(): void
    {
        $moving = InventoryItem::create([
            'name' => 'Moving', 'sku' => 'MOV-1', 'category' => 'FINISHED', 'unit' => 'bottles',
            'current_stock' => '100',
        ]);
        $this->exit($moving, -50, '2026-06-12');

        $still = InventoryItem::create([
            'name' => 'Still', 'sku' => 'STL-1', 'category' => 'FINISHED', 'unit' => 'bottles',
            'current_stock' => '100',
        ]);

        $cover = app(InventoryCoverQuery::class)->forItems([$moving, $still]);

        $this->assertSame(60, $cover[$moving->getKey()]); // round(100 × 30 / 50)
        $this->assertNull($cover[$still->getKey()]);
    }

    public function test_an_empty_set_of_items_returns_an_empty_map(): void
    {
        $this->assertSame([], app(InventoryCoverQuery::class)->forItems([]));
    }
}
