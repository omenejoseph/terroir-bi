<?php

declare(strict_types=1);

namespace App\Services\Cellar;

use App\Models\Vessel;
use App\Models\VesselLot;
use App\Models\WineLot;
use App\Support\Quantity;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Moves wine between a lot and the vessels that hold it, keeping each vessel's
 * `current_volume` in sync (via VesselVolumeSync) and refusing to overfill a
 * vessel. All public methods run in a transaction with the touched rows locked.
 */
class LotVolumeService
{
    public function __construct(private readonly VesselVolumeSync $sync) {}

    /**
     * Place `$volume` litres of `$lot` into `$vessel` (creating or topping up the
     * vessel_lot). Guards against exceeding the vessel's free capacity.
     */
    public function assignToVessel(WineLot $lot, Vessel $vessel, string $volume): VesselLot
    {
        $volume = Quantity::normalize($volume);

        if (Quantity::compare($volume, '0.000') <= 0) {
            throw ValidationException::withMessages([
                'volume' => 'Assigned volume must be greater than zero.',
            ]);
        }

        return DB::transaction(function () use ($lot, $vessel, $volume): VesselLot {
            /** @var Vessel $vessel */
            $vessel = Vessel::query()->whereKey($vessel->getKey())->lockForUpdate()->firstOrFail();

            $this->assertFits($vessel, $volume);

            $vesselLot = VesselLot::query()
                ->where('vessel_id', $vessel->getKey())
                ->where('wine_lot_id', $lot->getKey())
                ->lockForUpdate()
                ->first();

            if ($vesselLot === null) {
                $vesselLot = VesselLot::create([
                    'vessel_id' => $vessel->getKey(),
                    'wine_lot_id' => $lot->getKey(),
                    'volume' => $volume,
                ]);
            } else {
                $vesselLot->volume = Quantity::add((string) $vesselLot->volume, $volume);
                $vesselLot->save();
            }

            $this->sync->sync($vessel);

            return $vesselLot;
        });
    }

    /**
     * Rack: move `$volume` of a lot from one vessel to another within the same
     * lot. The lot's total volume is unchanged; both vessels are resynced.
     */
    public function moveWithinLot(WineLot $lot, string $fromVesselId, Vessel $toVessel, string $volume): void
    {
        $volume = Quantity::normalize($volume);

        DB::transaction(function () use ($lot, $fromVesselId, $toVessel, $volume): void {
            $source = VesselLot::query()
                ->where('wine_lot_id', $lot->getKey())
                ->where('vessel_id', $fromVesselId)
                ->lockForUpdate()
                ->first();

            if ($source === null || Quantity::compare((string) $source->volume, $volume) < 0) {
                throw ValidationException::withMessages([
                    'volume_liters' => 'The source vessel does not hold that much of this lot.',
                ]);
            }

            $this->applyToVesselLot($source, Quantity::negate($volume));
            $this->sync->syncMany([$fromVesselId]);
            $this->assignToVessel($lot, $toVessel, $volume);
        });
    }

    /** Remove a lot's presence from a vessel and resync that vessel. */
    public function unassign(VesselLot $vesselLot): void
    {
        DB::transaction(function () use ($vesselLot): void {
            $vesselId = $vesselLot->vessel_id;
            $vesselLot->delete();
            $this->sync->syncMany([$vesselId]);
        });
    }

    /**
     * Adjust a lot's `current_volume` by `$delta` (signed) and apply the same
     * change to the vessel holding it. When the lot occupies more than one
     * vessel, `$vesselId` must name which one. Refuses to drive volume negative
     * or to overfill the target vessel.
     */
    public function adjust(WineLot $lot, string $delta, ?string $vesselId = null): WineLot
    {
        $delta = Quantity::normalize($delta);

        return DB::transaction(function () use ($lot, $delta, $vesselId): WineLot {
            /** @var WineLot $lot */
            $lot = WineLot::query()->whereKey($lot->getKey())->lockForUpdate()->firstOrFail();

            $newLotVolume = Quantity::add((string) $lot->current_volume, $delta);
            if (Quantity::compare($newLotVolume, '0.000') < 0) {
                throw ValidationException::withMessages([
                    'volume' => 'Adjustment would drive the lot volume negative.',
                ]);
            }

            $vesselLots = $lot->vesselLots()->lockForUpdate()->get();
            $target = null;

            if ($vesselId !== null) {
                $target = $vesselLots->firstWhere('vessel_id', $vesselId);
                if ($target === null) {
                    throw ValidationException::withMessages([
                        'vessel_id' => 'That vessel does not hold this lot.',
                    ]);
                }
            } elseif ($vesselLots->count() === 1) {
                $target = $vesselLots->first();
            } elseif ($vesselLots->count() > 1) {
                throw ValidationException::withMessages([
                    'vessel_id' => 'This lot spans several vessels — specify which one to adjust.',
                ]);
            }

            if ($target instanceof VesselLot) {
                if (Quantity::compare($delta, '0.000') > 0) {
                    /** @var Vessel $vessel */
                    $vessel = Vessel::query()->whereKey($target->vessel_id)->lockForUpdate()->firstOrFail();
                    $this->assertFits($vessel, $delta);
                }
                $this->applyToVesselLot($target, $delta);
                $this->sync->syncMany([$target->vessel_id]);
            }

            $lot->current_volume = $newLotVolume;
            $lot->save();

            return $lot;
        });
    }

    /**
     * Remove `$volume` litres from a lot, draining its vessels in order, and
     * reduce the lot's `current_volume`. Used by bottling. Refuses to remove more
     * than the lot holds.
     */
    public function drain(WineLot $lot, string $volume): WineLot
    {
        $volume = Quantity::normalize($volume);

        return DB::transaction(function () use ($lot, $volume): WineLot {
            /** @var WineLot $lot */
            $lot = WineLot::query()->whereKey($lot->getKey())->lockForUpdate()->firstOrFail();

            if (Quantity::compare($volume, (string) $lot->current_volume) > 0) {
                throw ValidationException::withMessages([
                    'volume_used' => 'Cannot bottle more than the lot holds.',
                ]);
            }

            $remaining = $volume;
            $affected = [];
            foreach ($lot->vesselLots()->lockForUpdate()->get() as $vesselLot) {
                if (Quantity::compare($remaining, '0.000') <= 0) {
                    break;
                }
                $take = Quantity::compare((string) $vesselLot->volume, $remaining) <= 0
                    ? (string) $vesselLot->volume
                    : $remaining;
                $this->applyToVesselLot($vesselLot, Quantity::negate($take));
                $remaining = Quantity::sub($remaining, $take);
                $affected[] = $vesselLot->vessel_id;
            }

            $lot->current_volume = Quantity::sub((string) $lot->current_volume, $volume);
            $lot->save();

            $this->sync->syncMany($affected);

            return $lot;
        });
    }

    private function assertFits(Vessel $vessel, string $volume): void
    {
        $free = Quantity::sub((string) $vessel->capacity_liters, (string) $vessel->current_volume);
        if (Quantity::compare($volume, $free) > 0) {
            throw ValidationException::withMessages([
                'volume' => "{$vessel->name} has only {$free} L free.",
            ]);
        }
    }

    private function applyToVesselLot(VesselLot $vesselLot, string $delta): void
    {
        $newVolume = Quantity::add((string) $vesselLot->volume, $delta);
        if (Quantity::compare($newVolume, '0.000') <= 0) {
            $vesselLot->delete();

            return;
        }
        $vesselLot->volume = $newVolume;
        $vesselLot->save();
    }
}
