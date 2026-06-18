<?php

declare(strict_types=1);

namespace App\Actions\Cellar;

use App\Models\EnologicalProduct;

/** Create an enological product (additive). */
class CreateEnologicalProductAction
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function execute(array $data): EnologicalProduct
    {
        return EnologicalProduct::create([
            'name' => $data['name'],
            'category' => $data['category'],
            'unit' => $data['unit'],
            'current_stock' => $data['current_stock'] ?? 0,
            'min_stock' => $data['min_stock'] ?? null,
            'cost_per_unit' => isset($data['cost_per_unit']) ? (int) $data['cost_per_unit'] : null,
            'manufacturer' => $data['manufacturer'] ?? null,
            'packaging_size' => $data['packaging_size'] ?? null,
            'so2_uplift_per_unit' => $data['so2_uplift_per_unit'] ?? null,
            'supplier_id' => $data['supplier_id'] ?? null,
            'notes' => $data['notes'] ?? null,
        ]);
    }
}
