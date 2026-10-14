<?php

declare(strict_types=1);

namespace App\Actions\Cellar;

use App\Enums\EnologicalStockSource;
use App\Models\EnologicalProduct;
use App\Services\Cellar\EnologicalStockLedger;
use Illuminate\Support\Facades\DB;

/** Create an enological product (additive). */
class CreateEnologicalProductAction
{
    public function __construct(private readonly EnologicalStockLedger $ledger) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function execute(array $data): EnologicalProduct
    {
        return DB::transaction(function () use ($data): EnologicalProduct {
            $product = EnologicalProduct::create([
                'name' => $data['name'],
                'category' => $data['category'],
                'unit' => $data['unit'],
                'current_stock' => 0,
                'min_stock' => $data['min_stock'] ?? null,
                'cost_per_unit' => isset($data['cost_per_unit']) ? (int) $data['cost_per_unit'] : null,
                'manufacturer' => $data['manufacturer'] ?? null,
                'packaging_size' => $data['packaging_size'] ?? null,
                'so2_uplift_per_unit' => $data['so2_uplift_per_unit'] ?? null,
                'supplier_id' => $data['supplier_id'] ?? null,
                'notes' => $data['notes'] ?? null,
            ]);

            // The opening balance is the first movement in the product's history.
            $opening = (string) ($data['current_stock'] ?? '0');
            if ((float) $opening > 0) {
                $product = $this->ledger->move($product, $opening, EnologicalStockSource::Opening, $product, 'Opening stock', 'current_stock');
            }

            return $product;
        });
    }
}
