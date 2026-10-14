<?php

declare(strict_types=1);

namespace App\Actions\Cellar;

use App\Models\CellarAddition;
use App\Models\EnologicalProduct;
use App\Support\Quantity;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Correct a recorded addition. Stock follows the change: what the old line
 * drew is given back to its product, then the corrected line draws from its
 * (possibly different) product under the same rule as a new addition — it may
 * not drive stock negative. Total cost is recomputed from the unit cost.
 */
class UpdateCellarAdditionAction
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function execute(CellarAddition $addition, array $data): CellarAddition
    {
        return DB::transaction(function () use ($addition, $data): CellarAddition {
            /** @var CellarAddition $addition */
            $addition = CellarAddition::query()->whereKey($addition->getKey())->lockForUpdate()->firstOrFail();

            $oldProductId = $addition->enological_product_id;
            $oldQuantity = (string) $addition->quantity;

            $addition->fill(array_intersect_key($data, array_flip([
                'name', 'category', 'quantity', 'unit', 'note', 'vessel_id', 'enological_product_id',
            ])));
            if (array_key_exists('cost_per_unit', $data)) {
                $addition->setAttribute('cost_per_unit', $data['cost_per_unit'] !== null ? (int) $data['cost_per_unit'] : null);
            }
            $addition->quantity = Quantity::normalize((string) $addition->quantity);

            $unit = $addition->cost_per_unit?->getMinorAmount();
            $addition->setAttribute('total_cost', $unit !== null ? (int) round($unit * (float) $addition->quantity) : null);

            if ($oldProductId !== null) {
                $old = EnologicalProduct::query()->whereKey($oldProductId)->lockForUpdate()->first();
                if ($old !== null) {
                    $old->current_stock = Quantity::add((string) $old->current_stock, $oldQuantity);
                    $old->save();
                }
            }

            if ($addition->enological_product_id !== null) {
                $new = EnologicalProduct::query()->whereKey($addition->enological_product_id)->lockForUpdate()->firstOrFail();
                $after = Quantity::sub((string) $new->current_stock, (string) $addition->quantity);
                if (Quantity::isNegative($after)) {
                    throw ValidationException::withMessages([
                        'quantity' => "Not enough {$new->name} in stock — {$new->current_stock} {$new->unit} available, {$addition->quantity} needed.",
                    ]);
                }
                $new->current_stock = $after;
                $new->save();
            }

            $addition->save();

            return $addition;
        });
    }
}
