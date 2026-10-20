<?php

declare(strict_types=1);

namespace App\Services\Inventory;

use App\Models\InventoryItem;
use Illuminate\Support\Collection;

/**
 * A wine's vintages as a chain: each item names the vintage before it (`previous_vintage_id`), so the
 * latest vintage is the head and the older ones hang beneath it. Chains are short (a handful of
 * vintages), so these walk one level per query rather than reaching for a recursive query.
 */
class InventoryVintageChains
{
    private const MAX_DEPTH = 50;

    /**
     * The older vintages under each head, newest first.
     *
     * @param  iterable<InventoryItem>  $heads
     * @return array<string, list<InventoryItem>> head id → its older vintages
     */
    public function olderThan(iterable $heads): array
    {
        $result = [];
        /** @var array<string, string> $cursor head id → the id whose previous vintage to fetch next */
        $cursor = [];

        foreach ($heads as $head) {
            $result[$head->getKey()] = [];
            if ($head->previous_vintage_id !== null) {
                $cursor[$head->getKey()] = $head->previous_vintage_id;
            }
        }

        for ($depth = 0; $depth < self::MAX_DEPTH && $cursor !== []; $depth++) {
            /** @var Collection<string, InventoryItem> $level */
            $level = InventoryItem::query()->whereIn('id', array_values($cursor))->get()->keyBy('id');
            $next = [];

            foreach ($cursor as $headId => $itemId) {
                $item = $level->get($itemId);
                if ($item === null) {
                    continue;
                }

                $result[$headId][] = $item;
                if ($item->previous_vintage_id !== null) {
                    $next[$headId] = $item->previous_vintage_id;
                }
            }

            $cursor = $next;
        }

        return $result;
    }

    /**
     * The latest vintage each id belongs to (an id with no newer vintage is its own head).
     *
     * @param  list<string>  $ids
     * @return list<string>
     */
    public function headsOf(array $ids): array
    {
        /** @var array<string, string> $current id as given → the item we are looking above now */
        $current = array_combine($ids, $ids);
        $heads = [];

        for ($depth = 0; $depth < self::MAX_DEPTH && $current !== []; $depth++) {
            $newer = InventoryItem::query()
                ->whereIn('previous_vintage_id', array_values($current))
                ->pluck('id', 'previous_vintage_id');

            $next = [];
            foreach ($current as $origin => $id) {
                if (isset($newer[$id])) {
                    $next[$origin] = $newer[$id];
                } else {
                    $heads[$id] = true;
                }
            }

            $current = $next;
        }

        return array_keys($heads);
    }

    /** Every id in the chain an item belongs to — what moves together when one is dragged elsewhere. @return list<string> */
    public function chainOf(string $itemId): array
    {
        $ids = [$itemId];

        // Down: the older vintages.
        $cursor = InventoryItem::query()->whereKey($itemId)->value('previous_vintage_id');
        for ($depth = 0; $cursor !== null && $depth < self::MAX_DEPTH; $depth++) {
            $ids[] = $cursor;
            $cursor = InventoryItem::query()->whereKey($cursor)->value('previous_vintage_id');
        }

        // Up: the newer vintages.
        $cursor = InventoryItem::query()->where('previous_vintage_id', $itemId)->value('id');
        for ($depth = 0; $cursor !== null && $depth < self::MAX_DEPTH; $depth++) {
            $ids[] = $cursor;
            $cursor = InventoryItem::query()->where('previous_vintage_id', $cursor)->value('id');
        }

        return array_values(array_unique($ids));
    }

    /** Would making `$previousId` the previous vintage of `$itemId` loop the chain back on itself? */
    public function wouldLoop(string $itemId, string $previousId): bool
    {
        $cursor = $previousId;

        for ($depth = 0; $cursor !== null && $depth < self::MAX_DEPTH; $depth++) {
            if ($cursor === $itemId) {
                return true;
            }

            $cursor = InventoryItem::query()->whereKey($cursor)->value('previous_vintage_id');
        }

        return false;
    }
}
