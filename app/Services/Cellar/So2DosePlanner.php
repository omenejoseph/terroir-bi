<?php

declare(strict_types=1);

namespace App\Services\Cellar;

use App\Models\EnologicalProduct;
use App\Models\VesselLot;
use App\Models\WineLot;
use App\Support\Quantity;
use Illuminate\Validation\ValidationException;

/**
 * Turns an SO₂ request from the SO₂ screen into concrete dose lines — the one
 * place the dose is computed, used by preview, "Apply now" and "Plan for
 * cellar team", so what is shown is exactly what is applied.
 *
 * Request: {enological_product_id, mode: add|target, items: [{wine_lot_id, vessel_id?, mg_l}]}
 *  - mode "add" (the prototype's behaviour): mg_l is the free SO₂ to add;
 *  - mode "target": mg_l is the free SO₂ level to reach.
 * Each line's volume is what the vessel holds of that lot (or the lot's
 * volume when it is not in a vessel); its current level is the vessel's own
 * latest reading, else the lot's. Lines needing no SO₂ are dropped.
 */
class So2DosePlanner
{
    public function __construct(
        private readonly So2Calculator $so2,
        private readonly LatestAnalyses $latest,
    ) {}

    /**
     * @param  array{enological_product_id: string, mode?: string|null, items: list<array{wine_lot_id: string, vessel_id?: string|null, mg_l: float|int|string}>}  $request
     * @return array{
     *     product: array{id: string, name: string, unit: string, current_stock: string, method: string},
     *     lines: list<array<string, mixed>>,
     *     totals: array{quantity: string, cost: int|null, lines: int, stock_after: string, short_by: string|null}
     * }
     */
    public function plan(array $request): array
    {
        /** @var EnologicalProduct $product */
        $product = EnologicalProduct::query()->whereKey($request['enological_product_id'])->firstOrFail();
        $target = ($request['mode'] ?? 'add') === 'target';

        $lotIds = array_values(array_unique(array_map(fn (array $i): string => (string) $i['wine_lot_id'], $request['items'])));
        $lots = WineLot::query()->with('vesselLots.vessel')->whereIn('id', $lotIds)->get()->keyBy(fn (WineLot $l): string => (string) $l->getKey());
        $readings = $this->latest->perVessel($lotIds, 'free_so2');

        $lines = [];
        $total = '0.000';
        $cost = $product->cost_per_unit !== null ? 0 : null;
        $method = 'uplift';

        foreach ($request['items'] as $i => $item) {
            $lot = $lots[(string) $item['wine_lot_id']] ?? null;
            if ($lot === null) {
                throw ValidationException::withMessages(["items.{$i}.wine_lot_id" => 'Unknown wine lot.']);
            }

            $vesselId = isset($item['vessel_id']) && $item['vessel_id'] !== '' ? (string) $item['vessel_id'] : null;
            $vesselLot = null;
            if ($vesselId !== null) {
                $vesselLot = $lot->vesselLots->first(fn (VesselLot $vl): bool => (string) $vl->vessel_id === $vesselId);
                if ($vesselLot === null) {
                    throw ValidationException::withMessages(["items.{$i}.vessel_id" => "That vessel does not hold {$lot->name}."]);
                }
            }

            $byVessel = $readings[(string) $lot->getKey()] ?? [];
            $reading = ($vesselId !== null ? ($byVessel[$vesselId] ?? null) : null) ?? $this->latest->lotReading($byVessel);
            $current = $reading?->free_so2 !== null ? (float) $reading->free_so2 : null;

            $mgL = (float) $item['mg_l'];
            $targetMgL = $target ? $mgL : ($current ?? 0.0) + $mgL;
            $add = max(0.0, $targetMgL - ($current ?? 0.0));
            $volume = (float) ($vesselLot !== null ? $vesselLot->volume : $lot->current_volume);

            $dose = $this->so2->doseForProduct($product, $targetMgL, $current ?? 0.0, $volume);
            $method = $dose['method'];
            if ($dose['quantity'] <= 0) {
                continue;
            }

            $quantity = Quantity::normalize((string) $dose['quantity']);
            $total = Quantity::add($total, $quantity);
            $lineCost = $product->cost_per_unit?->totalFor($dose['quantity']);
            if ($lineCost !== null && $cost !== null) {
                $cost += $lineCost;
            }

            $lines[] = [
                'wine_lot_id' => (string) $lot->getKey(),
                'lot_number' => $lot->lot_number,
                'lot_name' => $lot->name,
                'vessel_id' => $vesselId,
                'vessel_name' => $vesselLot?->vessel?->name,
                'volume' => Quantity::normalize((string) $volume),
                'current_free_so2' => $current,
                'add_mg_l' => round($add, 1),
                'target_mg_l' => round($targetMgL, 1),
                'quantity' => $quantity,
                'unit' => $dose['unit'],
                'cost' => $lineCost,
                'note' => $this->note($add, $current, $targetMgL),
            ];
        }

        usort($lines, fn (array $a, array $b): int => strnatcasecmp((string) ($a['vessel_name'] ?? $a['lot_name']), (string) ($b['vessel_name'] ?? $b['lot_name'])));

        $stockAfter = Quantity::sub((string) $product->current_stock, $total);

        return [
            'product' => [
                'id' => (string) $product->getKey(),
                'name' => $product->name,
                'unit' => $product->unit,
                'current_stock' => (string) $product->current_stock,
                'method' => $method,
            ],
            'lines' => $lines,
            'totals' => [
                'quantity' => $total,
                'cost' => $cost,
                'lines' => count($lines),
                'stock_after' => $stockAfter,
                'short_by' => Quantity::isNegative($stockAfter) ? Quantity::negate($stockAfter) : null,
            ],
        ];
    }

    /** "SO₂ +10 mg/L (18 → 28)" — written on the addition and the planned item. */
    private function note(float $add, ?float $current, float $target): string
    {
        $fmt = fn (float $v): string => rtrim(rtrim(number_format($v, 1, '.', ''), '0'), '.');

        return 'SO₂ +'.$fmt($add).' mg/L ('.($current !== null ? $fmt($current) : '?').' → '.$fmt($target).')';
    }
}
