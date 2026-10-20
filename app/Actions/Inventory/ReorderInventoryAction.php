<?php

declare(strict_types=1);

namespace App\Actions\Inventory;

use App\Models\InventoryItem;
use App\Models\ProductCategory;
use Illuminate\Support\Facades\DB;

/**
 * Persists a drag-and-drop arrangement on the Inventory list: the order of items within a band, or the
 * order of categories / subcategories. Unknown or cross-tenant ids are ignored (tenant scope on the update).
 */
class ReorderInventoryAction
{
    /**
     * @param  list<string>  $orderedIds
     */
    public function items(array $orderedIds): void
    {
        DB::transaction(function () use ($orderedIds): void {
            foreach ($orderedIds as $index => $id) {
                InventoryItem::query()->whereKey($id)->update(['sort_order' => $index]);
            }
        });
    }

    /**
     * @param  list<array{group: string, subcategory?: string|null}>  $entries  in the new order; an entry
     *                                                                          without a subcategory is the group itself
     */
    public function groups(string $category, array $entries): void
    {
        DB::transaction(function () use ($category, $entries): void {
            foreach ($entries as $index => $entry) {
                $group = ProductCategory::query()
                    ->where('type', $category)->whereNull('parent_id')->where('name', $entry['group'])->first();

                $target = ($entry['subcategory'] ?? '') === ''
                    ? $group
                    : ($group === null ? null : ProductCategory::query()->where('parent_id', $group->getKey())->where('name', $entry['subcategory'])->first());

                $target?->update(['sort_order' => $index]);
            }
        });
    }
}
