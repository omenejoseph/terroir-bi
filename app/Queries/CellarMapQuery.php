<?php

declare(strict_types=1);

namespace App\Queries;

use App\DataTransferObjects\VesselData;
use App\Models\CellarAddition;
use App\Models\EnologicalProduct;
use App\Models\Vessel;
use App\Models\VesselLot;
use App\Services\Cellar\LatestAnalyses;

/**
 * The cellar map: every active vessel with what it holds, and per lot in it
 * the SO₂ the tile shows — the reading taken in that vessel when there is
 * one, else the lot's — plus the latest addition made there and the free SO₂
 * it should have produced (product uplift ÷ litres in the vessel), as on the
 * vessel page. Batched: a fixed number of queries however many vessels.
 */
class CellarMapQuery
{
    public function __construct(private readonly LatestAnalyses $latest) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function get(): array
    {
        $vessels = Vessel::query()
            ->with('vesselLots.wineLot')
            ->where('is_active', true)
            ->get()
            ->sortBy(fn (Vessel $v): string => $v->name, SORT_NATURAL | SORT_FLAG_CASE)
            ->values();

        $lotIds = array_values(array_unique($vessels
            ->flatMap(fn (Vessel $v) => $v->vesselLots->map(fn (VesselLot $vl): string => (string) $vl->wine_lot_id))
            ->all()));
        $free = $this->latest->perVessel($lotIds, 'free_so2');
        $additions = $this->latestAdditions($lotIds);

        return array_values($vessels->map(function (Vessel $v) use ($free, $additions): array {
            $row = VesselData::fromModel($v, true)->toArray();
            $vesselId = (string) $v->getKey();
            /** @var list<array<string, mixed>> $lots */
            $lots = $row['lots'] ?? [];

            foreach ($lots as $i => $content) {
                $lotId = (string) $content['wine_lot_id'];
                $own = $free[$lotId][$vesselId] ?? null;
                $reading = $own ?? $this->latest->lotReading($free[$lotId] ?? []);
                $value = $reading?->free_so2 !== null ? (float) $reading->free_so2 : null;
                $addition = $additions[$lotId][$vesselId] ?? null;
                $projected = null;
                if ($value !== null && $addition !== null
                    && $addition->created_at !== null && $addition->created_at->greaterThan($reading->date)) {
                    $projected = $this->projected($value, $addition, (float) $content['volume']);
                }

                $lots[$i]['so2'] = [
                    'free' => $value,
                    'total' => $reading?->total_so2 !== null ? (float) $reading->total_so2 : null,
                    'date' => $reading?->date->toIso8601String(),
                    'in_vessel' => $own !== null,
                    'projected' => $projected,
                    'latest_addition' => $addition !== null && $projected !== null
                        ? ['name' => $addition->name, 'quantity' => (string) $addition->quantity, 'unit' => $addition->unit]
                        : null,
                ];
            }
            $row['lots'] = $lots;

            return $row;
        })->all());
    }

    /**
     * Latest addition per (lot, vessel).
     *
     * @param  list<string>  $lotIds
     * @return array<string, array<string, CellarAddition>>
     */
    private function latestAdditions(array $lotIds): array
    {
        if ($lotIds === []) {
            return [];
        }

        $out = [];
        $rows = CellarAddition::query()
            ->with('enologicalProduct')
            ->whereIn('wine_lot_id', $lotIds)
            ->whereNotNull('vessel_id')
            ->orderByDesc('created_at')
            ->get();
        foreach ($rows as $a) {
            $out[(string) $a->wine_lot_id][(string) $a->vessel_id] ??= $a;
        }

        return $out;
    }

    private function projected(float $free, CellarAddition $addition, float $liters): ?float
    {
        $product = $addition->enologicalProduct;
        $uplift = $product instanceof EnologicalProduct && $product->so2_uplift_per_unit !== null
            ? (float) $product->so2_uplift_per_unit
            : null;

        if ($uplift === null || $uplift <= 0 || $liters <= 0) {
            return null;
        }

        return round($free + (float) $addition->quantity * $uplift / $liters, 1);
    }
}
