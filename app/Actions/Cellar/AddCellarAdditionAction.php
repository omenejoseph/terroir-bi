<?php

declare(strict_types=1);

namespace App\Actions\Cellar;

use App\Enums\EnologicalStockSource;
use App\Models\CellarAddition;
use App\Models\WineLot;
use App\Services\Cellar\EnologicalStockLedger;
use App\Support\Quantity;
use Illuminate\Support\Facades\DB;

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
    public function __construct(private readonly EnologicalStockLedger $ledger) {}

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

            // Draws the product's stock — refused (and rolled back) if it would go negative.
            if (! empty($data['enological_product_id'])) {
                $this->ledger->move((string) $data['enological_product_id'], Quantity::negate($quantity), EnologicalStockSource::Addition, $addition, $lot->lot_number);
            }

            return $addition;
        });
    }
}
