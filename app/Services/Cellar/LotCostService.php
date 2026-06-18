<?php

declare(strict_types=1);

namespace App\Services\Cellar;

use App\Models\WineLot;

/**
 * Rolls a lot's costs (grape + additions) into totals and per-unit figures, all
 * in integer minor units. Used by the lot overview and by bottling to snapshot a
 * produced item's cost-per-bottle.
 */
class LotCostService
{
    /**
     * @return array{total: int, additions: int, grape: int, per_liter: int, per_bottle_750: int}
     */
    public function breakdown(WineLot $lot): array
    {
        $grape = $lot->grape_cost?->getMinorAmount() ?? 0;

        $additions = (int) $lot->additions()->sum('total_cost');

        $total = $grape + $additions;

        $initial = (float) $lot->initial_volume;
        $perLiter = $initial > 0 ? (int) round($total / $initial) : 0;

        return [
            'total' => $total,
            'additions' => $additions,
            'grape' => $grape,
            'per_liter' => $perLiter,
            'per_bottle_750' => (int) round($perLiter * 0.75),
        ];
    }

    /** Cost (minor units) of one bottle of the given size, drawn from the lot. */
    public function costPerBottle(WineLot $lot, int $bottleVolumeMl): int
    {
        $perLiter = $this->breakdown($lot)['per_liter'];

        return (int) round($perLiter * ($bottleVolumeMl / 1000));
    }
}
