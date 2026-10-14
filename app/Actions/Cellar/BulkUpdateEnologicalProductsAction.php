<?php

declare(strict_types=1);

namespace App\Actions\Cellar;

use App\Models\EnologicalProduct;
use Illuminate\Support\Facades\DB;

/**
 * Bulk edit on the products list: apply the same category, supplier, minimum
 * stock, cost or active flag to the selected products. Stock is not
 * bulk-editable — it only moves through the stock ledger.
 */
class BulkUpdateEnologicalProductsAction
{
    private const FIELDS = ['category', 'supplier_id', 'min_stock', 'cost_per_unit', 'is_active', 'manufacturer'];

    /**
     * @param  list<string>  $ids
     * @param  array<string, mixed>  $fields
     */
    public function execute(array $ids, array $fields): int
    {
        $fields = array_intersect_key($fields, array_flip(self::FIELDS));

        return DB::transaction(function () use ($ids, $fields): int {
            $count = 0;
            foreach (EnologicalProduct::query()->whereIn('id', $ids)->get() as $product) {
                $product->fill($fields)->save(); // per-model save so each change is audited
                $count++;
            }

            return $count;
        });
    }
}
