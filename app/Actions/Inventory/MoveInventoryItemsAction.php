<?php

declare(strict_types=1);

namespace App\Actions\Inventory;

use App\Models\InventoryItem;
use App\Models\ProductCategory;
use App\Services\Inventory\InventoryVintageChains;
use Illuminate\Support\Facades\DB;

/**
 * Drag and drop between categories. A product moves together with all its vintages — the whole chain
 * lands in the new category — so a wine is never split across two. Only a category of the item's own
 * type is accepted: a finished wine does not become a raw material by being dropped on one.
 */
class MoveInventoryItemsAction
{
    public function __construct(private readonly InventoryVintageChains $chains) {}

    /**
     * @param  list<string>  $itemIds
     * @return int how many items moved (vintages included)
     */
    public function execute(array $itemIds, ?string $categoryId): int
    {
        $category = $categoryId === null ? null : ProductCategory::query()->with('parent')->findOrFail($categoryId);

        return DB::transaction(function () use ($itemIds, $category): int {
            $ids = [];
            foreach ($itemIds as $id) {
                array_push($ids, ...$this->chains->chainOf($id));
            }

            $moved = 0;
            foreach (InventoryItem::query()->whereIn('id', array_unique($ids))->get() as $item) {
                if ($category !== null && $item->category !== $category->type) {
                    continue;
                }

                // Saved through the model so the group / subcategory text follows the category.
                $item->product_category_id = $category?->getKey();
                $item->save();
                $moved++;
            }

            return $moved;
        });
    }
}
