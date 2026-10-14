<?php

declare(strict_types=1);

namespace App\Queries;

use App\Models\CellarAddition;
use App\Models\CellarAnalysis;
use App\Models\CellarTastingNote;
use App\Models\EnologicalProduct;
use App\Models\Vessel;
use App\Models\VesselLot;
use App\Services\Cellar\LatestAnalyses;

/**
 * The extra sections of a vessel's page beyond its specs and contents: the
 * analyses and tasting notes taken *in this vessel* (any lot), and per lot it
 * holds, the latest SO₂ reading here, the latest addition made here and the
 * free SO₂ that addition should have produced.
 *
 * The projection uses the addition's product SO₂ uplift and this vessel's
 * litres of the lot, and only applies to an addition made after the reading —
 * the prototype parsed "(20 → 30" out of the note text instead.
 */
class VesselDetailQuery
{
    public function __construct(private readonly LatestAnalyses $latest) {}

    /**
     * @return array{analyses: list<array<string, mixed>>, tasting_notes: list<array<string, mixed>>, so2: list<array<string, mixed>>}
     */
    public function get(Vessel $vessel): array
    {
        $vesselId = (string) $vessel->getKey();

        $analyses = CellarAnalysis::query()
            ->with('wineLot')
            ->where('vessel_id', $vesselId)
            ->orderByDesc('date')
            ->orderByDesc('created_at')
            ->limit(10)
            ->get()
            ->map(function (CellarAnalysis $a): array {
                $row = [
                    'id' => (string) $a->getKey(),
                    'wine_lot_id' => (string) $a->wine_lot_id,
                    'lot_name' => $a->wineLot?->name,
                    'date' => $a->date->toIso8601String(),
                    'note' => $a->note,
                ];
                foreach (CellarAnalysis::PARAMETERS as $param) {
                    $value = $a->getAttribute($param);
                    $row[$param] = $value !== null ? (string) $value : null;
                }

                return $row;
            })
            ->all();

        $notes = CellarTastingNote::query()
            ->with('wineLot')
            ->where('vessel_id', $vesselId)
            ->orderByDesc('date')
            ->limit(20)
            ->get()
            ->map(fn (CellarTastingNote $n): array => [
                'id' => (string) $n->getKey(),
                'wine_lot_id' => (string) $n->wine_lot_id,
                'lot_name' => $n->wineLot?->name,
                'date' => $n->date->toIso8601String(),
                'score' => $n->score,
                'appearance' => $n->appearance,
                'nose' => $n->nose,
                'palate' => $n->palate,
                'overall' => $n->overall,
                'note' => $n->note,
            ])
            ->all();

        return [
            'analyses' => array_values($analyses),
            'tasting_notes' => array_values($notes),
            'so2' => $this->so2($vessel),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function so2(Vessel $vessel): array
    {
        $vesselId = (string) $vessel->getKey();
        $holdings = $vessel->vesselLots()->get();
        $lotIds = array_values(array_map(fn (VesselLot $vl): string => (string) $vl->wine_lot_id, $holdings->all()));
        $readings = $this->latest->perVessel($lotIds, 'free_so2');

        $out = [];
        foreach ($holdings as $vl) {
            /** @var VesselLot $vl */
            $lotId = (string) $vl->wine_lot_id;
            $reading = $readings[$lotId][$vesselId] ?? $this->latest->lotReading($readings[$lotId] ?? []);
            $addition = CellarAddition::query()
                ->with('enologicalProduct')
                ->where('wine_lot_id', $lotId)
                ->where('vessel_id', $vesselId)
                ->latest('created_at')
                ->first();

            $free = $reading?->free_so2 !== null ? (float) $reading->free_so2 : null;
            $projected = null;
            if ($free !== null && $addition !== null
                && $addition->created_at !== null && $addition->created_at->greaterThan($reading->date)) {
                $projected = $this->projected($free, $addition, (float) $vl->volume);
            }

            $out[] = [
                'wine_lot_id' => $lotId,
                'free_so2' => $free,
                'total_so2' => $reading?->total_so2 !== null ? (float) $reading->total_so2 : null,
                'reading_date' => $reading?->date->toIso8601String(),
                'reading_in_vessel' => isset($readings[$lotId][$vesselId]),
                'latest_addition' => $addition !== null ? [
                    'id' => (string) $addition->getKey(),
                    'name' => $addition->name,
                    'quantity' => (string) $addition->quantity,
                    'unit' => $addition->unit,
                    'date' => $addition->created_at?->toIso8601String(),
                ] : null,
                'projected_free_so2' => $projected,
            ];
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
