<?php

declare(strict_types=1);

namespace App\Actions\Inventory;

use App\Models\InventoryItem;
use App\Models\ProductCategory;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Deletes a category — only while no inventory sits in it (or, for a top-level one, in any of its
 * subcategories, which go with it). Nothing is ever orphaned: the answer is "empty it first".
 */
class DeleteProductCategoryAction
{
    /** @throws ValidationException when the category still holds items */
    public function execute(ProductCategory $category): void
    {
        $ids = [$category->getKey(), ...ProductCategory::query()->where('parent_id', $category->getKey())->pluck('id')->all()];

        $held = InventoryItem::query()->whereIn('product_category_id', $ids)->count();

        if ($held > 0) {
            throw ValidationException::withMessages([
                'category' => __('":name" still holds :count item(s). Move them to another category first.', ['name' => $category->name, 'count' => $held]),
            ]);
        }

        DB::transaction(function () use ($category): void {
            ProductCategory::query()->where('parent_id', $category->getKey())->delete();
            $category->delete();
        });
    }
}
