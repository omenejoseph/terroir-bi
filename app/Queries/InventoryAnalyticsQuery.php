<?php

declare(strict_types=1);

namespace App\Queries;

use App\Models\InventoryItem;
use App\Support\Money\CurrencyRegistry;
use App\Tenancy\Contracts\TenantContext;

/**
 * Read-optimised inventory analytics for the dashboard/inventory charts.
 *
 * Each metric is a single tenant-scoped query that projects only the columns it
 * needs (no Money cast hydration, no N+1): a raw SUM for value-by-category, one
 * pass for low-stock, and a bounded, indexed sort for stock levels.
 */
class InventoryAnalyticsQuery
{
    public function __construct(private readonly TenantContext $tenant) {}

    /**
     * @return array{
     *   stock_levels: array<int, array{name: string, stock: string}>,
     *   value: array{total: int, currency: string, categories: list<array{category: string, value: int}>},
     *   low_stock: array{below: list<array{name: string, stock: string, min: string}>, approaching: list<array{name: string, stock: string, min: string}>}
     * }
     */
    public function get(int $stockLimit = 12, float $approachingFactor = 1.5): array
    {
        return [
            'stock_levels' => $this->stockLevels($stockLimit),
            'value' => $this->valueByCategory(),
            'low_stock' => $this->lowStock($approachingFactor),
        ];
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
