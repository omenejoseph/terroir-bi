<?php

declare(strict_types=1);

namespace App\Services\Cellar;

use App\Models\CostItem;
use App\Models\WineLot;

/**
 * Rolls a lot's costs (grape + additions) into totals and per-unit figures, all
 * in integer minor units. Used by the lot overview and by bottling to snapshot a
 * produced item's cost-per-bottle.
 */
class LotCostService
{
    /**
     * A lot's cost: grape cost + additions + cost lines charged to the lot
     * (cost_items.wine_lot_id) + cost carried in by blends. Cost per litre is
     * over the lot's initial volume — the one definition used on the lot, the
     * costs page, bottling and analytics.
     *
     * `$additionsMinor` / `$linkedMinor` let a caller listing many lots at once
     * (e.g. CellarCostsQuery) pass pre-aggregated sums instead of this method
     * running a query per lot.
     *
     * @return array{total: int, additions: int, grape: int, linked: int, carried: int, per_liter: int, per_bottle_750: int}
     */
    public function breakdown(WineLot $lot, ?int $additionsMinor = null, ?int $linkedMinor = null): array
    {
        $grape = $lot->grape_cost?->getMinorAmount() ?? 0;
        $additions = $additionsMinor ?? (int) $lot->additions()->sum('total_cost');
        $linked = $linkedMinor ?? (int) CostItem::query()->where('wine_lot_id', $lot->getKey())->sum('total');
        $carried = $lot->carried_cost;

        $total = $grape + $additions + $linked + $carried;

        $initial = (float) $lot->initial_volume;
        $perLiter = $initial > 0 ? (int) round($total / $initial) : 0;

        return [
            'total' => $total,
            'additions' => $additions,
            'grape' => $grape,
            'linked' => $linked,
            'carried' => $carried,
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
