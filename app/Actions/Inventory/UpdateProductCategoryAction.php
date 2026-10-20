<?php

declare(strict_types=1);

namespace App\Actions\Inventory;

use App\Models\InventoryItem;
use App\Models\ProductCategory;
use Illuminate\Support\Facades\DB;

/**
 * Renames a category and/or flips whether it carries vintages.
 *
 * A rename rewrites the group / subcategory text on the items under it (reports read that text).
 * Switching vintages off unlinks the chains of the items in it: with no vintages, nothing is folded
 * under another row any more, and every item stands on its own again.
 */
class UpdateProductCategoryAction
{
    /**
     * @param  array{name?: string, has_vintage?: bool}  $attributes
     */
    public function execute(ProductCategory $category, array $attributes): ProductCategory
    {
        return DB::transaction(function () use ($category, $attributes): ProductCategory {
            $renamed = array_key_exists('name', $attributes) && trim($attributes['name']) !== $category->name;
            $vintagesOff = array_key_exists('has_vintage', $attributes) && $category->has_vintage && ! $attributes['has_vintage'];

            if (array_key_exists('name', $attributes)) {
                $category->name = trim($attributes['name']);
            }
            if (array_key_exists('has_vintage', $attributes)) {
                $category->has_vintage = $attributes['has_vintage'];
            }
            $category->save();

            if ($renamed) {
                $this->mirrorName($category);
            }

            if ($vintagesOff) {
                InventoryItem::query()
                    ->whereIn('product_category_id', $this->scope($category))
                    ->update(['previous_vintage_id' => null]);
            }

            return $category;
        });
    }

    private function mirrorName(ProductCategory $category): void
    {
        if ($category->isSubcategory()) {
            InventoryItem::query()->where('product_category_id', $category->getKey())->update(['subcategory' => $category->name]);

            return;
        }

        InventoryItem::query()->whereIn('product_category_id', $this->scope($category))->update(['group' => $category->name]);
    }

    /** The category's own id and, for a top-level one, its subcategories'. @return list<string> */
    private function scope(ProductCategory $category): array
    {
        return [
            $category->getKey(),
            ...ProductCategory::query()->where('parent_id', $category->getKey())->pluck('id')->all(),
        ];
    }
}
