<?php

declare(strict_types=1);

namespace App\Queries;

use App\Models\InventoryItem;

/**
 * Where an item's stock sits among its siblings — the Item — View drawer's
 * Provenance line ("Lowest stock of the six wines", Figma 378:1592). Ranked
 * within the item's own `group` (e.g. "Wine"), since that is the only
 * grouping the design's example implies. An item with no group, or the only
 * active item in its group, has nothing to rank against.
 */
class ItemStockRankingQuery
{
    /**
     * @return array{rank: int, total: int}|null
     */
    public function get(InventoryItem $item): ?array
    {
        if ($item->group === null) {
            return null;
        }

        $siblings = InventoryItem::query()
            ->where('group', $item->group)
            ->where('is_active', true)
            ->get(['id', 'current_stock']);

        if ($siblings->count() < 2) {
            return null;
        }

        $ordered = $siblings
            ->sortBy(fn (InventoryItem $sibling): float => (float) $sibling->current_stock)
            ->values();

        $rank = $ordered->search(fn (InventoryItem $sibling): bool => $sibling->is($item));

        // Only reachable if $item itself somehow isn't active/grouped the same
        // way any more between the caller's read and this one — the query
        // above is built from $item's own group and is_active.
        if ($rank === false) {
            return null;
        }

        return ['rank' => $rank + 1, 'total' => $ordered->count()];
    }
}
