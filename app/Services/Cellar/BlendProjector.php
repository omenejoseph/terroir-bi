<?php

declare(strict_types=1);

namespace App\Services\Cellar;

use App\Models\VesselLot;
use App\Models\WineLot;
use App\Support\Quantity;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * "Projected blending (by lot ratios)": given lots and their share of a
 * target volume, project the blend's analysis, composition, type and cost
 * before anything is moved — and turn the ratios into vessel sources for
 * BlendService when the blend is executed.
 *
 * Each parameter is the volume-weighted mean of the lots' latest readings,
 * except pH, which is logarithmic: it is averaged as hydrogen-ion
 * concentration and converted back (the prototype averaged pH directly).
 * Grades use the prototype's optimal / watch ranges.
 */
class BlendProjector
{
    /** @var array<string, array{optimal: array{float, float}, watch: array{float, float}}> */
    public const RANGES = [
        'ph' => ['optimal' => [3.1, 3.6], 'watch' => [2.9, 3.8]],
        'total_acidity' => ['optimal' => [5.0, 8.0], 'watch' => [4.0, 10.0]],
        'volatile_acidity' => ['optimal' => [0.0, 0.6], 'watch' => [0.0, 0.9]],
        'alcohol' => ['optimal' => [11.0, 15.0], 'watch' => [9.0, 16.5]],
        'residual_sugar' => ['optimal' => [0.0, 9.0], 'watch' => [0.0, 45.0]],
        'free_so2' => ['optimal' => [20.0, 40.0], 'watch' => [10.0, 50.0]],
        'total_so2' => ['optimal' => [80.0, 150.0], 'watch' => [50.0, 200.0]],
    ];

    public function __construct(
        private readonly LatestAnalyses $latest,
        private readonly GrapeComposition $grapes,
        private readonly LotCostService $costs,
    ) {}

    /**
     * @param  list<array{wine_lot_id: string, ratio: float|int|string}>  $components
     * @return array<string, mixed>
     */
    public function project(array $components, float $targetVolume): array
    {
        $lots = $this->lots($components);
        $ratios = $this->ratios($components);
        $lotIds = array_keys($ratios);

        $parts = [];
        foreach ($ratios as $lotId => $ratio) {
            $lot = $lots[$lotId];
            $volume = round($targetVolume * $ratio, 3);
            $parts[] = [
                'wine_lot_id' => $lotId,
                'lot_number' => $lot->lot_number,
                'name' => $lot->name,
                'ratio' => round($ratio * 100, 2),
                'volume' => number_format($volume, 3, '.', ''),
                'available' => (string) $lot->current_volume,
                'enough' => (float) $lot->current_volume + 0.0005 >= $volume,
            ];
        }

        $analysis = [];
        foreach (array_keys(self::RANGES) as $param) {
            $readings = $this->latest->perLot($lotIds, $param);
            $weight = 0.0;
            $sum = 0.0;
            foreach ($ratios as $lotId => $ratio) {
                if (! isset($readings[$lotId])) {
                    continue;
                }
                $value = (float) $readings[$lotId]->getAttribute($param);
                $sum += $ratio * ($param === 'ph' ? 10 ** (-$value) : $value);
                $weight += $ratio;
            }
            if ($weight <= 0) {
                $analysis[$param] = ['value' => null, 'grade' => null, 'coverage' => 0.0];

                continue;
            }
            $mean = $sum / $weight;
            $value = $param === 'ph' ? round(-log10($mean), 2) : round($mean, 2);
            $analysis[$param] = ['value' => $value, 'grade' => $this->grade($param, $value), 'coverage' => round($weight * 100, 1)];
        }

        $composition = [];
        foreach ($ratios as $lotId => $ratio) {
            foreach ($this->grapes->shares($lots[$lotId]) as $variety => $share) {
                $composition[$variety] = ($composition[$variety] ?? 0.0) + $share * $ratio;
            }
        }
        arsort($composition);

        $types = collect($lots)->map(fn (WineLot $l) => $l->wine_type?->value)->filter()->unique();
        $costPerLiter = 0;
        foreach ($ratios as $lotId => $ratio) {
            $costPerLiter += $this->costs->breakdown($lots[$lotId])['per_liter'] * $ratio;
        }

        return [
            'target_volume' => number_format($targetVolume, 3, '.', ''),
            'components' => $parts,
            'analysis' => $analysis,
            'composition' => array_map(fn (float $s): float => round($s * 100, 2), $composition),
            'wine_type' => $types->count() === 1 ? $types->first() : null,
            'cost_per_liter' => (int) round($costPerLiter),
            'feasible' => collect($parts)->every(fn (array $p): bool => $p['enough']),
        ];
    }

    /**
     * The vessel sources BlendService needs: each lot's litres taken from its
     * vessels in the order the wine went in (oldest first).
     *
     * @param  list<array{wine_lot_id: string, ratio: float|int|string}>  $components
     * @return list<array{vessel_lot_id: string, volume: string}>
     */
    public function sources(array $components, float $targetVolume): array
    {
        $sources = [];
        foreach ($this->ratios($components) as $lotId => $ratio) {
            $remaining = number_format($targetVolume * $ratio, 3, '.', '');
            $holdings = VesselLot::query()->where('wine_lot_id', $lotId)->orderBy('added_at')->orderBy('created_at')->get();

            foreach ($holdings as $vl) {
                if (Quantity::compare($remaining, '0.000') <= 0) {
                    break;
                }
                $take = Quantity::compare((string) $vl->volume, $remaining) < 0 ? (string) $vl->volume : $remaining;
                $sources[] = ['vessel_lot_id' => (string) $vl->getKey(), 'volume' => $take];
                $remaining = Quantity::sub($remaining, $take);
            }

            if (Quantity::compare($remaining, '0.000') > 0) {
                throw ValidationException::withMessages(['components' => "Not enough wine in the vessels of one lot — {$remaining} L short."]);
            }
        }

        return $sources;
    }

    /** @return 'optimal'|'watch'|'concern' */
    public function grade(string $param, float $value): string
    {
        $r = self::RANGES[$param];

        return match (true) {
            $value >= $r['optimal'][0] && $value <= $r['optimal'][1] => 'optimal',
            $value >= $r['watch'][0] && $value <= $r['watch'][1] => 'watch',
            default => 'concern',
        };
    }

    /**
     * @param  list<array{wine_lot_id: string, ratio: float|int|string}>  $components
     * @return array<string, float> lot id → share (0–1)
     */
    private function ratios(array $components): array
    {
        $total = array_sum(array_map(fn (array $c): float => (float) $c['ratio'], $components));
        if (abs($total - 100) > 0.5) {
            throw ValidationException::withMessages(['components' => 'The ratios must add up to 100 %.']);
        }

        $out = [];
        foreach ($components as $c) {
            $out[(string) $c['wine_lot_id']] = ($out[(string) $c['wine_lot_id']] ?? 0.0) + (float) $c['ratio'] / $total;
        }

        return $out;
    }

    /**
     * @param  list<array{wine_lot_id: string, ratio: float|int|string}>  $components
     * @return array<string, WineLot>
     */
    private function lots(array $components): array
    {
        /** @var Collection<int, WineLot> $lots */
        $lots = WineLot::query()->whereIn('id', array_map(fn (array $c): string => (string) $c['wine_lot_id'], $components))->get();

        return $lots->keyBy(fn (WineLot $l): string => (string) $l->getKey())->all();
    }
}
