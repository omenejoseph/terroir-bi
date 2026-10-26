<?php

declare(strict_types=1);

namespace App\Queries;

use App\Models\CellarAnalysis;
use App\Models\VesselLot;
use App\Models\WineLot;

/**
 * Every vessel (barrel, tank …) a lot sits in, for the lot page's Barrels tab and its overview:
 * where it is (room), what it is, how much of this lot is in it and how full it is, what else it
 * holds, and the newest reading of each parameter taken in it (falling back to a lot-level reading,
 * flagged, when none was taken in that vessel) — plus the COMBINED row: total litres and, per
 * parameter, the average of those readings weighted by the litres in each barrel. Barrels with no
 * reading of a parameter are left out of that average and counted, never treated as zero.
 *
 * @phpstan-type Reading array{value: float, date: string, in_vessel: bool}
 */
class LotBarrelsQuery
{
    /** The parameters shown per barrel and averaged across them. */
    public const PARAMS = ['free_so2', 'total_so2', 'ph', 'alcohol', 'volatile_acidity', 'total_acidity', 'temperature'];

    /**
     * With a `$vessel`, only that barrel: its row, and a "combined" row that is just that barrel too.
     *
     * @return array{rows: list<array<string, mixed>>, combined: array<string, mixed>}
     */
    public function get(WineLot $lot, ?string $vessel = null): array
    {
        $lotId = (string) $lot->getKey();
        $vesselLots = VesselLot::query()
            ->with(['vessel.vesselLots.wineLot:id,name,vintage'])
            ->where('wine_lot_id', $lotId)
            ->get()
            ->filter(fn (VesselLot $vl): bool => $vl->vessel !== null && ($vessel === null || (string) $vl->vessel_id === $vessel))
            ->sortBy(fn (VesselLot $vl): string => $vl->vessel->name ?? '', SORT_NATURAL | SORT_FLAG_CASE)
            ->values();

        $readings = $this->readings($lot);

        $rows = [];
        foreach ($vesselLots as $vl) {
            $vessel = $vl->vessel;
            if ($vessel === null) {
                continue;
            }
            $vesselId = (string) $vessel->getKey();
            $capacity = (float) $vessel->capacity_liters;
            $held = (float) $vessel->current_volume;

            $own = [];
            foreach (self::PARAMS as $param) {
                $own[$param] = $readings[$param][$vesselId] ?? ($readings[$param][''] ?? null);
                if ($own[$param] !== null) {
                    $own[$param] = [...$own[$param], 'in_vessel' => isset($readings[$param][$vesselId])];
                }
            }

            $others = $vessel->vesselLots
                ->filter(fn (VesselLot $o): bool => (string) $o->wine_lot_id !== $lotId && $o->wineLot !== null)
                ->map(fn (VesselLot $o): array => ['lot_id' => (string) $o->wine_lot_id, 'name' => $o->wineLot?->name, 'vintage' => $o->wineLot?->vintage, 'volume' => (string) $o->volume])
                ->values()->all();

            $rows[] = [
                'vessel_id' => $vesselId,
                'name' => $vessel->name,
                'type' => $vessel->type->value,
                'material' => $vessel->material,
                'room' => $vessel->room,
                'location' => $vessel->location,
                'capacity_liters' => (string) $vessel->capacity_liters,
                'volume' => (string) $vl->volume,
                'fill_percent' => $capacity > 0 ? round($held / $capacity * 100, 1) : 0.0,
                'is_faulty' => (bool) $vessel->is_faulty,
                'others' => $others,
                'readings' => $own,
            ];
        }

        return ['rows' => $rows, 'combined' => $this->combined($rows)];
    }

    /**
     * Newest reading per parameter per vessel ('' = lot-level), for this lot only.
     *
     * @return array<string, array<string, array{value: float, date: string}>>
     */
    private function readings(WineLot $lot): array
    {
        $out = [];
        $analyses = CellarAnalysis::query()
            ->select(['id', 'wine_lot_id', 'vessel_id', 'date', 'created_at', ...self::PARAMS])
            ->where('wine_lot_id', $lot->getKey())
            ->orderByDesc('date')->orderByDesc('created_at')
            ->get();

        foreach ($analyses as $a) {
            foreach (self::PARAMS as $param) {
                $value = $a->getAttribute($param);
                if ($value === null) {
                    continue;
                }
                $out[$param][(string) $a->vessel_id] ??= ['value' => (float) $value, 'date' => $a->date->toIso8601String()];
            }
        }

        return $out;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, mixed>
     */
    private function combined(array $rows): array
    {
        $total = array_sum(array_map(fn (array $r): float => (float) $r['volume'], $rows));
        $params = [];

        foreach (self::PARAMS as $param) {
            $weighted = 0.0;
            $weight = 0.0;
            $counted = 0;
            $values = [];

            foreach ($rows as $r) {
                $reading = $r['readings'][$param] ?? null;
                if ($reading === null) {
                    continue;
                }
                $litres = (float) $r['volume'];
                $weighted += $reading['value'] * $litres;
                $weight += $litres;
                $counted++;
                $values[] = $reading['value'];
            }

            $params[$param] = [
                'average' => $weight > 0 ? round($weighted / $weight, 2) : null,
                'min' => $values === [] ? null : min($values),
                'max' => $values === [] ? null : max($values),
                'barrels_counted' => $counted,
            ];
        }

        return [
            'barrels' => count($rows),
            'volume' => number_format($total, 3, '.', ''),
            'capacity' => number_format(array_sum(array_map(fn (array $r): float => (float) $r['capacity_liters'], $rows)), 3, '.', ''),
            'parameters' => $params,
        ];
    }
}
