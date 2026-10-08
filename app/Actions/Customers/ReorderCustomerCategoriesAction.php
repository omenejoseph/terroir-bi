<?php

declare(strict_types=1);

namespace App\Actions\Customers;

use App\Models\CustomerCategory;
use Illuminate\Support\Facades\DB;

class ReorderCustomerCategoriesAction
{
    /**
     * @param  list<string>  $orderedIds  Category ids in the order they should appear.
     */
    public function execute(array $orderedIds): void
    {
        DB::transaction(function () use ($orderedIds): void {
            foreach ($orderedIds as $position => $id) {
                // Tenant-scoped query: an id from another organisation simply matches nothing.
                CustomerCategory::query()->whereKey($id)->update(['sort_order' => $position + 1]);
            }
        });
    }
}
