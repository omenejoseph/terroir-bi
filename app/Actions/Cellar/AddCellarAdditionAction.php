<?php

declare(strict_types=1);

namespace App\Actions\Cellar;

use App\Models\CellarAddition;
use App\Models\EnologicalProduct;
use App\Models\WineLot;
use App\Support\Quantity;
use Illuminate\Support\Facades\DB;

/**
 * Record an enological addition on a lot. When it draws from a stocked
 * enological product, that product's stock is decremented in the same
 * transaction. Total cost is derived from the unit cost when not supplied.
 */
class AddCellarAdditionAction
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function execute(WineLot $lot, array $data, string $createdById): CellarAddition
    {
        return DB::transaction(function () use ($lot, $data, $createdById): CellarAddition {
            $quantity = Quantity::normalize((string) $data['quantity']);
            $costPerUnit = isset($data['cost_per_unit']) ? (int) $data['cost_per_unit'] : null;
            $totalCost = isset($data['total_cost'])
                ? (int) $data['total_cost']
                : ($costPerUnit !== null ? (int) round($costPerUnit * (float) $quantity) : null);

            /** @var CellarAddition $addition */
            $addition = $lot->additions()->create([
                'enological_product_id' => $data['enological_product_id'] ?? null,
                'created_by_id' => $createdById,
                'name' => $data['name'],
                'category' => $data['category'] ?? null,
                'quantity' => $quantity,
                'unit' => $data['unit'],
                'cost_per_unit' => $costPerUnit,
                'total_cost' => $totalCost,
                'note' => $data['note'] ?? null,
            ]);

            if (! empty($data['enological_product_id'])) {
                $product = EnologicalProduct::query()
                    ->whereKey((string) $data['enological_product_id'])
                    ->lockForUpdate()
                    ->first();
                if ($product !== null) {
                    $product->current_stock = Quantity::sub((string) $product->current_stock, $quantity);
                    $product->save();
                }
            }

            return $addition;
        });
    }
}
