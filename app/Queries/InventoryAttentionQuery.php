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
            'no_min_stock' => $this->noMinStock(),
            'no_cost_per_unit' => $this->noCostPerUnit(),
            'zero_stock' => $this->zeroStock(),
            'no_movement_90d' => $this->dormant(),
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

    private function baseQuery(): Builder
    {
        return InventoryItem::query()->where('is_active', true);
    }

    private function noMinStock(): int
    {
        return $this->baseQuery()->whereNull('min_stock')->count();
    }

    private function noCostPerUnit(): int
    {
        return $this->baseQuery()->whereNull('cost_per_unit')->count();
    }

    private function zeroStock(): int
    {
        return $this->baseQuery()->where('current_stock', '<=', 0)->count();
    }

    /**
     * Active items with no stock movement in the last 90 days. Items that have
     * never moved count as dormant too — they are exactly the stale rows the
     * band is meant to surface.
     */
    private function dormant(): int
    {
        $cutoff = Carbon::now()->subDays(self::DORMANT_DAYS);

        return $this->baseQuery()
            ->whereNotExists(function ($query) use ($cutoff): void {
                $query->selectRaw('1')
                    ->from((new StockMovement)->getTable())
                    ->whereColumn('stock_movements.inventory_item_id', 'inventory_items.id')
                    ->where('stock_movements.created_at', '>=', $cutoff);
            })
            ->count();
    }
}
