<?php

declare(strict_types=1);

namespace App\Actions\Cellar;

use App\Models\EnologicalProduct;

/** Update an enological product's fields (not its stock — use AdjustStock). */
class UpdateEnologicalProductAction
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function execute(EnologicalProduct $product, array $data): EnologicalProduct
    {
        $product->fill(array_intersect_key($data, array_flip([
            'name', 'category', 'unit', 'min_stock', 'cost_per_unit', 'manufacturer',
            'packaging_size', 'so2_uplift_per_unit', 'supplier_id', 'is_active', 'notes',
        ])));
        $product->save();

        return $product;
    }
}
