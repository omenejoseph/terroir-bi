<?php

declare(strict_types=1);

namespace App\Actions\Inventory;

use App\Models\ProductCategory;
use Illuminate\Support\Facades\DB;

class ReorderProductCategoriesAction
{
    /**
     * @param  list<string>  $orderedIds  sibling categories (same level, same parent) in their new order
     */
    public function execute(array $orderedIds): void
    {
        DB::transaction(function () use ($orderedIds): void {
            foreach ($orderedIds as $index => $id) {
                ProductCategory::query()->whereKey($id)->update(['sort_order' => $index]);
            }
        });
    }
}
