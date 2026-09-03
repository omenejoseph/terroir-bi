<?php

declare(strict_types=1);

namespace App\Queries;

use App\Models\InventoryItem;
use App\Models\StockMovement;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * "Cover" (Figma 389:1592's inventory list column) for a page of items at
 * once — the same days-of-stock-left math as
 * InventoryItemStockAnalyticsQuery::exits() ("stock ÷ trailing exit rate"),
 * just computed set-wise so a page of N rows costs one grouped query instead
 * of N. Product Detail's own Cover figure (default `period=30d`) uses the
 * same window, so a customer never sees the list and the drawer disagree.
 */
class InventoryCoverQuery
{
    /**
     * @param  iterable<InventoryItem>  $items
     * @return array<string, int|null> item id => days of stock left, null
     *                                 when nothing exited in the window
     */
    public function forItems(iterable $items, int $days = 30): array
    {
        $items = Collection::make($items);

        if ($items->isEmpty()) {
            return [];
        }

        $exitedByItem = StockMovement::query()
            ->whereIn('inventory_item_id', $items->pluck('id'))
            ->where('quantity', '<', 0)
            ->where('created_at', '>=', Carbon::now()->subDays($days))
            ->groupBy('inventory_item_id')
            ->selectRaw('inventory_item_id, SUM(ABS(quantity)) as exited')
            ->pluck('exited', 'inventory_item_id');

        return $items->mapWithKeys(function (InventoryItem $item) use ($exitedByItem, $days): array {
            // Movements are recorded in the item's own unit — a case-unit item
            // exiting "2" moved 2 cases, not 2 bottles — so both stock and
            // exits are normalised to bottles the same way the drawer does.
            $bpc = max(1, (int) $item->bottles_per_case);
            $caseUnit = in_array(strtolower((string) $item->unit), ['case', 'cases'], true);
            $toBottles = fn (float $qty): float => $caseUnit ? $qty * $bpc : $qty;

            $bottlesExited = (int) round($toBottles((float) ($exitedByItem[$item->getKey()] ?? 0)));

            if ($bottlesExited <= 0) {
                return [$item->getKey() => null];
            }

            $stockBottles = (int) round($toBottles((float) $item->current_stock));

            return [$item->getKey() => (int) round($stockBottles * $days / $bottlesExited)];
        })->all();
    }
}
