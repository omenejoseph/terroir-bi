<?php

declare(strict_types=1);

namespace App\Actions\Cellar;

use App\Models\CellarAddition;
use App\Models\EnologicalProduct;
use App\Models\WineLot;
use App\Support\Quantity;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Record an enological addition on a lot, optionally into one of its vessels.
 * When it draws from a stocked enological product, that product's stock is
 * decremented in the same transaction — and the addition is refused when the
 * stock would go negative (every addition path — single, bulk, SO₂, planned —
 * funnels through here, so this is the one stock rule). Total cost is derived
 * from the unit cost when not supplied.
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

            // Lock and check stock before writing anything.
            $product = null;
            if (! empty($data['enological_product_id'])) {
                $product = EnologicalProduct::query()
                    ->whereKey((string) $data['enological_product_id'])
                    ->lockForUpdate()
                    ->first();
                if ($product !== null && Quantity::isNegative(Quantity::sub((string) $product->current_stock, $quantity))) {
                    throw ValidationException::withMessages([
                        'quantity' => "Not enough {$product->name} in stock — {$product->current_stock} {$product->unit} available, {$quantity} needed.",
                    ]);
                }
            }
            $costPerUnit = isset($data['cost_per_unit']) ? (int) $data['cost_per_unit'] : null;
            $totalCost = isset($data['total_cost'])
                ? (int) $data['total_cost']
                : ($costPerUnit !== null ? (int) round($costPerUnit * (float) $quantity) : null);

            /** @var CellarAddition $addition */
            $addition = $lot->additions()->create([
                'enological_product_id' => $data['enological_product_id'] ?? null,
                'vessel_id' => $data['vessel_id'] ?? null,
                'created_by_id' => $createdById,
                'name' => $data['name'],
                'category' => $data['category'] ?? null,
                'quantity' => $quantity,
                'unit' => $data['unit'],
                'cost_per_unit' => $costPerUnit,
                'total_cost' => $totalCost,
                'note' => $data['note'] ?? null,
            ]);

            if ($product !== null) {
                $product->current_stock = Quantity::sub((string) $product->current_stock, $quantity);
                $product->save();
            }

            return $addition;
        });
    }
}
