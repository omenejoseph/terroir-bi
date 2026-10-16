<?php

declare(strict_types=1);

namespace App\Queries;

use App\Models\InventoryItem;
use App\Models\StockMovement;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * Counts for the inventory "Needs attention" band (Figma 389:1592).
 *
 * Each entry is a data-quality or stock condition a user should act on. The
 * counts are computed here rather than in a controller so the API and the
 * Inertia page report the same numbers.
 *
 * The design also shows a "Reserved by open orders" count. That needs a
 * reservation concept the schema does not have yet (order lines do not hold
 * stock), so it is deliberately not returned — see docs/design/README.md.
 */
class InventoryAttentionQuery
{
    /** Days without a stock movement before an item counts as dormant. */
    private const DORMANT_DAYS = 90;

    /**
     * @return list<array{key: string, label: string, count: int}>
     */
    public function get(): array
    {
        $counts = [
            'no_min_stock' => $this->count('no_min_stock'),
            'no_cost_per_unit' => $this->count('no_cost_per_unit'),
            'zero_stock' => $this->count('zero_stock'),
            'no_movement_90d' => $this->count('no_movement_90d'),
        ];

        $labels = [
            'no_min_stock' => 'No min stock set',
            'no_cost_per_unit' => 'No cost per unit',
            'zero_stock' => 'Zero stock',
            'no_movement_90d' => 'No movement 90d',
        ];

        $out = [];

        foreach ($counts as $key => $count) {
            // A condition nobody is in is not worth a chip.
            if ($count > 0) {
                $out[] = ['key' => $key, 'label' => $labels[$key], 'count' => $count];
            }
        }

        return $out;
    }

    /** The chip keys the list can be filtered by. */
    public const KEYS = ['no_min_stock', 'no_cost_per_unit', 'zero_stock', 'no_movement_90d'];

    /**
     * Narrow a query to the items a chip counts. The chip's count and the rows it filters to are the
     * same definition, so clicking a chip can never show a different number of items than it promised.
     *
     * @param  Builder<InventoryItem>  $query
     * @return Builder<InventoryItem>
     */
    public static function scope(Builder $query, string $key): Builder
    {
        $query->where('is_active', true);

        return match ($key) {
            'no_min_stock' => $query->whereNull('min_stock'),
            'no_cost_per_unit' => $query->whereNull('cost_per_unit'),
            'zero_stock' => $query->where('current_stock', '<=', 0),
            'no_movement_90d' => $query->whereNotExists(function ($sub): void {
                $sub->selectRaw('1')
                    ->from((new StockMovement)->getTable())
                    ->whereColumn('stock_movements.inventory_item_id', 'inventory_items.id')
                    ->where('stock_movements.created_at', '>=', Carbon::now()->subDays(self::DORMANT_DAYS));
            }),
            default => $query,
        };
    }

    private function count(string $key): int
    {
        return self::scope(InventoryItem::query(), $key)->count();
    }
}
