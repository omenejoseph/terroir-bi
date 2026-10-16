<?php

declare(strict_types=1);

namespace App\Queries;

use App\Enums\WineLotStatus;
use App\Models\CellarAddition;
use App\Models\CostItem;
use App\Models\WineLot;
use App\Models\WineLotGrape;
use App\Services\Cellar\LotCostService;

/** Per-lot cost roll-up (grape + additions) for the Wine Costs page. */
class CellarCostsQuery
{
    public function __construct(private readonly LotCostService $costs) {}

    /**
     * The API's roll-up covers wine still in the cellar. The Costs page also
     * wants bottled lots (that is where margin means something), each lot's
     * selling price from the item it was bottled into, and the detail behind
     * the total for its expandable rows — `$detailed` adds all three.
     *
     * @return list<array<string, mixed>>
     */
    public function get(bool $detailed = false): array
    {
        $rows = WineLot::query()
            ->when(! $detailed, fn ($q) => $q->where('status', '!=', WineLotStatus::Bottled))
            ->when($detailed, fn ($q) => $q->where('status', '!=', WineLotStatus::Blended)
                ->with(['grapes', 'additions', 'costItems', 'latestBottling.inventoryItem']))
            // One aggregate query for every lot's additions total, instead of
            // LotCostService::breakdown() running its own additions() query
            // per lot below.
            ->withSum('additions as additions_total', 'total_cost')
            ->withSum('costItems as linked_total', 'total')
            ->orderByDesc('vintage')
            ->orderBy('name')
            ->get()
            ->map(function (WineLot $lot) use ($detailed): array {
                $row = [
                    'id' => $lot->getKey(),
                    'lot_number' => $lot->lot_number,
                    'name' => $lot->name,
                    'vintage' => $lot->vintage,
                    'current_volume' => (string) $lot->current_volume,
                    'cost' => $this->costs->breakdown(
                        $lot,
                        (int) ($lot->getAttribute('additions_total') ?? 0),
                        (int) ($lot->getAttribute('linked_total') ?? 0),
                    ),
                ];

                if (! $detailed) {
                    return $row;
                }

                $item = $lot->latestBottling?->inventoryItem;

                return [
                    ...$row,
                    'grape_variety' => $lot->grape_variety,
                    'status' => $lot->status->value,
                    'initial_volume' => (string) $lot->initial_volume,
                    'selling_price' => $item?->default_price?->getMinorAmount(),
                    'bottle_volume_ml' => $lot->latestBottling?->bottle_volume_ml,
                    'grapes' => array_values($lot->grapes->map(fn (WineLotGrape $g): array => [
                        'grape_variety' => $g->grape_variety,
                        'weight_kg' => $g->weight_kg !== null ? (string) $g->weight_kg : null,
                        'price_per_kg' => $g->price_per_kg?->getMinorAmount(),
                    ])->all()),
                    'additions' => array_values($lot->additions->map(fn (CellarAddition $a): array => [
                        'name' => $a->name,
                        'quantity' => (string) $a->quantity,
                        'unit' => $a->unit,
                        'total_cost' => $a->total_cost?->getMinorAmount(),
                    ])->all()),
                    'linked' => array_values($lot->costItems->map(fn (CostItem $c): array => [
                        'description' => $c->description,
                        'total' => $c->total->getMinorAmount(),
                    ])->all()),
                ];
            })
            ->all();

        return array_values($rows);
    }
}
