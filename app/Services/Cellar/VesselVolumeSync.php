<?php

declare(strict_types=1);

namespace App\Services\Cellar;

use App\Enums\VesselStatus;
use App\Models\Vessel;
use App\Support\Quantity;

/**
 * The single source of truth for vessel occupancy. Recomputes a vessel's
 * `current_volume` as the exact sum of its `vessel_lots` (fixed-scale integer
 * math, no float drift) and derives its status: a vessel holding wine is IN_USE,
 * an empty one becomes AVAILABLE. MAINTENANCE / RETIRED are operator-set and are
 * left untouched.
 */
class VesselVolumeSync
{
    /** Recompute one vessel from its vessel_lots and persist if changed. */
    public function sync(Vessel $vessel): void
    {
        $total = '0.000';
        foreach ($vessel->vesselLots()->get() as $vesselLot) {
            $total = Quantity::add($total, (string) $vesselLot->volume);
        }

        $vessel->current_volume = $total;

        // Only flip between AVAILABLE and IN_USE; never override a manual hold.
        if (! in_array($vessel->status, [VesselStatus::Maintenance, VesselStatus::Retired], true)) {
            $vessel->status = Quantity::compare($total, '0.000') > 0
                ? VesselStatus::InUse
                : VesselStatus::Available;
        }

        $vessel->save();
    }

    /**
     * Recompute several vessels by id (de-duplicated). Used by ops that touch
     * more than one vessel (transfers, blends, bottling).
     *
     * @param  iterable<string|null>  $vesselIds
     */
    public function syncMany(iterable $vesselIds): void
    {
        $ids = array_values(array_unique(array_filter(
            is_array($vesselIds) ? $vesselIds : iterator_to_array($vesselIds),
            static fn (?string $id): bool => $id !== null && $id !== '',
        )));

        if ($ids === []) {
            return;
        }

        foreach (Vessel::query()->whereKey($ids)->get() as $vessel) {
            $this->sync($vessel);
        }
    }
}
