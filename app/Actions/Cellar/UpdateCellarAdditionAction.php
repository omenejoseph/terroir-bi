<?php

declare(strict_types=1);

namespace App\Actions\Cellar;

use App\Enums\EnologicalStockSource;
use App\Models\CellarAddition;
use App\Models\EnologicalProduct;
use App\Services\Cellar\EnologicalStockLedger;
use App\Support\Money\UnitPrice;
use App\Support\Quantity;
use Illuminate\Support\Facades\DB;

/**
 * Correct a recorded addition. Stock follows the change: what the old line
 * drew is given back to its product, then the corrected line draws from its
 * (possibly different) product under the same rule as a new addition — it may
 * not drive stock negative. Total cost is recomputed from the unit cost.
 */
class UpdateCellarAdditionAction
{
    public function __construct(private readonly EnologicalStockLedger $ledger) {}

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
                $addition->setAttribute('cost_per_unit', $data['cost_per_unit'] !== null ? UnitPrice::normalize($data['cost_per_unit']) : null);
            }
            $addition->quantity = Quantity::normalize((string) $addition->quantity);

            $addition->setAttribute('total_cost', $addition->cost_per_unit?->totalFor((string) $addition->quantity));

            if ($oldProductId !== null && EnologicalProduct::query()->whereKey($oldProductId)->exists()) {
                $this->ledger->move($oldProductId, $oldQuantity, EnologicalStockSource::AdditionCorrected, $addition, 'Addition corrected — previous amount returned');
            }

            if ($addition->enological_product_id !== null) {
                $this->ledger->move($addition->enological_product_id, Quantity::negate((string) $addition->quantity), EnologicalStockSource::AdditionCorrected, $addition, 'Addition corrected');
            }

            $addition->save();

            return $addition;
        });
    }
}
