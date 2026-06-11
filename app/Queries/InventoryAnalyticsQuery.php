<?php

declare(strict_types=1);

namespace App\Queries;

use App\Models\InventoryItem;
use App\Models\OrderItem;
use App\Models\StockMovement;
use App\Support\Money\CurrencyRegistry;
use App\Support\Money\Money;
use App\Tenancy\Contracts\TenantContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Read-optimised inventory analytics for the dashboard/inventory charts.
 *
 * Each metric is a single tenant-scoped query that projects only the columns it
 * needs (no Money cast hydration, no N+1): a raw SUM for value-by-category, one
 * pass for low-stock, and a bounded, indexed sort for stock levels.
 */
class InventoryAnalyticsQuery
{
    // Per-row bottle conversion (literal SQL — case-unit storage scales by bottles_per_case).
    private const MOVE_BOTTLES = "ABS(stock_movements.quantity) * (CASE WHEN inventory_items.unit IN ('case', 'cases') THEN inventory_items.bottles_per_case ELSE 1 END)";

    private const MOVE_COST = "ABS(stock_movements.quantity) * (CASE WHEN inventory_items.unit IN ('case', 'cases') THEN inventory_items.bottles_per_case ELSE 1 END) * inventory_items.cost_per_unit";

    private const LINE_BOTTLES = "order_items.quantity * (CASE WHEN order_items.unit_type = 'cases' THEN inventory_items.bottles_per_case ELSE 1 END)";

    private const LINE_LIST_VALUE = "inventory_items.default_price * order_items.quantity * (CASE WHEN order_items.unit_type = 'cases' THEN inventory_items.bottles_per_case ELSE 1 END)";

    public function __construct(private readonly TenantContext $tenant) {}

    /**
     * @return array<string, mixed>
     */
    public function get(int $stockLimit = 12, float $approachingFactor = 1.5): array
    {
        return [
            'summary' => $this->summary(),
            'portfolio_exits' => $this->portfolioExits(),
            'movements_12m' => $this->movements12m(),
            'top_products' => $this->topProducts(8),
            'by_group' => $this->byGroup(),
            'stock_levels' => $this->stockLevels($stockLimit),
            'value' => $this->valueByCategory(),
            'low_stock' => $this->lowStock($approachingFactor),
        ];
    }

    /**
     * Headline counts + portfolio stock value (at price and at cost).
     *
     * @return array<string, mixed>
     */
    private function summary(): array
    {
        $currency = $this->currency();

        $byCategory = ['FINISHED' => 0, 'SEMI_FINISHED' => 0, 'RAW_MATERIAL' => 0];
        foreach (InventoryItem::query()->selectRaw('category, COUNT(*) as c')->groupBy('category')->get() as $row) {
            $byCategory[$row->category->value] = (int) $row->getAttribute('c');
        }

        $saleValue = (int) round((float) InventoryItem::query()->sum(DB::raw('current_stock * COALESCE(default_price, 0)')));
        $production = (int) round((float) InventoryItem::query()->sum(DB::raw('current_stock * COALESCE(cost_per_unit, 0)')));

        return [
            'total_active' => InventoryItem::query()->where('is_active', true)->count(),
            'low_stock' => InventoryItem::query()->whereNotNull('min_stock')->where('min_stock', '>', 0)
                ->whereColumn('current_stock', '<', 'min_stock')->count(),
            'out_of_stock' => InventoryItem::query()->where('is_active', true)->where('current_stock', '<=', 0)->count(),
            'for_sale' => InventoryItem::query()->where('is_for_sale', true)->count(),
            'by_category' => $byCategory,
            'priced_count' => InventoryItem::query()->where('default_price', '>', 0)->count(),
            'sale_value' => Money::fromMinor($saleValue, $currency)->jsonSerialize(),
            'production_value' => Money::fromMinor($production, $currency)->jsonSerialize(),
            'margin_percent' => $saleValue > 0
                ? number_format(($saleValue - $production) / $saleValue * 100, 0, '.', '')
                : '0',
        ];
    }

    /**
     * Warehouse exits over the trailing window: external (sales) vs blended (all
     * channels). Sales money comes from order lines; units from stock movements.
     *
     * @return array<string, mixed>
     */
    private function portfolioExits(int $days = 90): array
    {
        $currency = $this->currency();
        $from = Carbon::now()->subDays($days);

        $movements = fn () => StockMovement::query()
            ->join('inventory_items', 'inventory_items.id', '=', 'stock_movements.inventory_item_id')
            ->where('stock_movements.quantity', '<', 0)
            ->where('stock_movements.created_at', '>=', $from);

        $externalUnits = (int) round((float) $movements()
            ->where('stock_movements.type', 'ORDER_DEDUCT')->sum(DB::raw(self::MOVE_BOTTLES)));
        $blendedUnits = (int) round((float) $movements()->sum(DB::raw(self::MOVE_BOTTLES)));
        $blendedCost = (int) round((float) $movements()
            ->whereNotNull('inventory_items.cost_per_unit')->sum(DB::raw(self::MOVE_COST)));

        // Realized sales (order lines, non-consignment) in the window.
        $lines = fn () => OrderItem::query()
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->join('inventory_items', 'inventory_items.id', '=', 'order_items.inventory_item_id')
            ->where('orders.is_consignment', false)
            ->where('orders.created_at', '>=', $from);

        $revenue = (int) $lines()->sum('order_items.total');
        $cogs = (int) $lines()->whereNotNull('order_items.cost_per_unit')
            ->sum(DB::raw('order_items.cost_per_unit * order_items.quantity'));
        $bottlesSold = (int) round((float) $lines()->sum(DB::raw(self::LINE_BOTTLES)));
        $listValue = (int) round((float) $lines()->whereNotNull('inventory_items.default_price')
            ->sum(DB::raw(self::LINE_LIST_VALUE)));

        $money = fn (int $minor): mixed => Money::fromMinor($minor, $currency)->jsonSerialize();

        return [
            'period_days' => $days,
            'external' => [
                'units_exited' => $externalUnits,
                'cost_of_exits' => $bottlesSold > 0 ? $money($cogs) : null,
                'revenue_realized' => $revenue > 0 ? $money($revenue) : null,
                'mean_margin_percent' => $revenue > 0
                    ? number_format(($revenue - $cogs) / $revenue * 100, 1, '.', '') : null,
                'mean_price' => $bottlesSold > 0 ? $money((int) round($revenue / $bottlesSold)) : null,
                'off_target_percent' => $listValue > 0
                    ? number_format(($listValue - $revenue) / $listValue * 100, 1, '.', '') : null,
            ],
            'blended' => [
                'units_exited' => $blendedUnits,
                'cost_of_exits' => $blendedUnits > 0 && $blendedCost > 0 ? $money($blendedCost) : null,
                'revenue_realized' => $revenue > 0 ? $money($revenue) : null,
                'velocity_per_day' => number_format($blendedUnits / max(1, $days), 1, '.', ''),
            ],
        ];
    }

    /**
     * Monthly In/Out bottle totals for the trailing 12 months.
     *
     * @return list<array{month: string, in: int, out: int}>
     */
    private function movements12m(): array
    {
        $start = Carbon::now()->startOfMonth()->subMonths(11);

        /** @var array<string, array{in: float, out: float}> $buckets */
        $buckets = [];
        $rows = StockMovement::query()
            ->join('inventory_items', 'inventory_items.id', '=', 'stock_movements.inventory_item_id')
            ->where('stock_movements.created_at', '>=', $start)
            ->selectRaw("stock_movements.*, (CASE WHEN inventory_items.unit IN ('case', 'cases') THEN inventory_items.bottles_per_case ELSE 1 END) as factor")
            ->get();

        foreach ($rows as $movement) {
            $month = $movement->created_at?->format('Y-m') ?? '';
            $bottles = abs((float) $movement->quantity) * (int) $movement->getAttribute('factor');
            $buckets[$month] ??= ['in' => 0.0, 'out' => 0.0];
            if ((float) $movement->quantity >= 0) {
                $buckets[$month]['in'] += $bottles;
            } else {
                $buckets[$month]['out'] += $bottles;
            }
        }

        $series = [];
        $cursor = $start->copy();
        for ($i = 0; $i < 12; $i++) {
            $month = $cursor->format('Y-m');
            $series[] = [
                'month' => $month,
                'in' => (int) round($buckets[$month]['in'] ?? 0.0),
                'out' => (int) round($buckets[$month]['out'] ?? 0.0),
            ];
            $cursor->addMonth();
        }

        return $series;
    }

    /**
     * Top items by stock value (current_stock × default_price).
     *
     * @return array<int, array{name: string, value: int}>
     */
    private function topProducts(int $limit): array
    {
        return InventoryItem::query()
            ->whereNotNull('default_price')
            ->selectRaw('name, (current_stock * default_price) as value')
            ->orderByDesc('value')
            ->limit($limit)
            ->get()
            ->map(fn (InventoryItem $i): array => [
                'name' => $i->name,
                'value' => (int) round((float) $i->getAttribute('value')),
            ])
            ->all();
    }

    /**
     * Item counts per group (null group reported as null for the client to label).
     *
     * @return array<int, array{group: string|null, count: int}>
     */
    private function byGroup(): array
    {
        return InventoryItem::query()
            ->select('group')
            ->selectRaw('COUNT(*) as c')
            ->groupBy('group')
            ->orderByDesc('c')
            ->get()
            ->map(fn (InventoryItem $i): array => [
                'group' => $i->group,
                'count' => (int) $i->getAttribute('c'),
            ])
            ->all();
    }

    /** Number of items currently below their minimum stock (single COUNT). */
    public function lowStockCount(): int
    {
        return InventoryItem::query()
            ->whereNotNull('min_stock')
            ->whereColumn('current_stock', '<', 'min_stock')
            ->count();
    }

    /**
     * Items most at risk (lowest stock among those with a minimum set).
     *
     * @return array<int, array{name: string, stock: string, min: string}>
     */
    public function stockWatch(int $limit = 6): array
    {
        return InventoryItem::query()
            ->whereNotNull('min_stock')
            ->orderBy('current_stock')
            ->limit($limit)
            ->get(['name', 'current_stock', 'min_stock'])
            ->map(fn (InventoryItem $i): array => [
                'name' => $i->name,
                'stock' => (string) $i->current_stock,
                'min' => (string) $i->min_stock,
            ])
            ->all();
    }

    /**
     * @return array<int, array{name: string, stock: string}>
     */
    private function stockLevels(int $limit): array
    {
        return InventoryItem::query()
            ->where('is_active', true)
            ->orderByDesc('current_stock')
            ->limit($limit)
            ->get(['name', 'current_stock'])
            ->map(fn (InventoryItem $i): array => [
                'name' => $i->name,
                'stock' => (string) $i->current_stock,
            ])
            ->all();
    }

    /**
     * @return array{total: int, currency: string, categories: list<array{category: string, value: int}>}
     */
    private function valueByCategory(): array
    {
        $rows = InventoryItem::query()
            ->selectRaw('category, SUM(current_stock * COALESCE(default_price, 0)) as value')
            ->groupBy('category')
            ->get();

        $categories = [];
        $total = 0;
        foreach ($rows as $row) {
            $value = (int) round((float) $row->getAttribute('value'));
            $total += $value;
            $categories[] = [
                'category' => $row->category->value,
                'value' => $value,
            ];
        }

        return [
            'total' => $total,
            'currency' => $this->currency(),
            'categories' => $categories,
        ];
    }

    /**
     * @return array{below: list<array{name: string, stock: string, min: string}>, approaching: list<array{name: string, stock: string, min: string}>}
     */
    private function lowStock(float $approachingFactor): array
    {
        $items = InventoryItem::query()
            ->whereNotNull('min_stock')
            ->whereRaw('current_stock < min_stock * ?', [$approachingFactor])
            ->orderBy('current_stock')
            ->limit(20)
            ->get(['name', 'current_stock', 'min_stock']);

        $below = [];
        $approaching = [];
        foreach ($items as $item) {
            $row = [
                'name' => $item->name,
                'stock' => (string) $item->current_stock,
                'min' => (string) $item->min_stock,
            ];
            if ((float) $item->current_stock < (float) $item->min_stock) {
                $below[] = $row;
            } else {
                $approaching[] = $row;
            }
        }

        return ['below' => $below, 'approaching' => $approaching];
    }

    private function currency(): string
    {
        $tenant = $this->tenant->current();

        return $tenant?->settings->default_currency ?? CurrencyRegistry::default()->code;
    }
}
