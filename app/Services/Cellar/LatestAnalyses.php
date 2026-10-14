<?php

declare(strict_types=1);

namespace App\Services\Cellar;

use App\Models\CellarAnalysis;

/**
 * The newest analysis that has a given parameter set — per lot, or per
 * (lot, vessel) with lot-level readings keyed ''. Grouped in PHP (newest
 * date, then newest created_at) so it behaves the same on Postgres and SQLite.
 */
class LatestAnalyses
{
    /**
     * @param  list<string>  $lotIds
     * @return array<string, array<string, CellarAnalysis>> lot id → vessel id ('' = lot-level) → analysis
     */
    public function perVessel(array $lotIds, string $column): array
    {
        $out = [];
        foreach ($this->newestFirst($lotIds, $column) as $a) {
            $out[(string) $a->wine_lot_id][(string) $a->vessel_id] ??= $a;
        }

        return $out;
    }

    /**
     * @param  list<string>  $lotIds
     * @return array<string, CellarAnalysis> lot id → analysis
     */
    public function perLot(array $lotIds, string $column): array
    {
        $out = [];
        foreach ($this->newestFirst($lotIds, $column) as $a) {
            $out[(string) $a->wine_lot_id] ??= $a;
        }

        return $out;
    }

    /**
     * The reading for a lot as a whole: its lot-level one, else the newest
     * across its vessels.
     *
     * @param  array<string, CellarAnalysis>  $byVessel
     */
    public function lotReading(array $byVessel): ?CellarAnalysis
    {
        if (isset($byVessel[''])) {
            return $byVessel[''];
        }

        $newest = null;
        foreach ($byVessel as $a) {
            if ($newest === null || $a->date->greaterThan($newest->date)) {
                $newest = $a;
            }
        }

        return $newest;
    }

    /**
     * @param  list<string>  $lotIds
     * @return iterable<CellarAnalysis>
     */
    private function newestFirst(array $lotIds, string $column): iterable
    {
        if ($lotIds === []) {
            return [];
        }

        return CellarAnalysis::query()
            ->select(['id', 'wine_lot_id', 'vessel_id', 'date', 'created_at', 'free_so2', 'total_so2', 'ph', 'residual_sugar'])
            ->whereIn('wine_lot_id', $lotIds)
            ->whereNotNull($column)
            ->orderByDesc('date')
            ->orderByDesc('created_at')
            ->lazy();
    }
}
