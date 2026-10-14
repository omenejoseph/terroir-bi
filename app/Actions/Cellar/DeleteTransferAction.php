<?php

declare(strict_types=1);

namespace App\Actions\Cellar;

use App\Enums\CellarTransferType;
use App\Models\CellarTransfer;
use App\Models\Vessel;
use App\Models\WineLot;
use App\Services\Audit\AuditLogger;
use App\Services\Cellar\GrapeComposition;
use App\Services\Cellar\LotCostService;
use App\Services\Cellar\LotVolumeService;
use App\Support\Quantity;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/** Reverse a transfer (inverse of CreateTransferAction), then delete the record. */
class DeleteTransferAction
{
    public function __construct(
        private readonly LotVolumeService $volumes,
        private readonly GrapeComposition $grapes,
        private readonly LotCostService $costs,
        private readonly AuditLogger $audit,
    ) {}

    public function execute(CellarTransfer $transfer): void
    {
        DB::transaction(function () use ($transfer): void {
            $volume = (string) $transfer->volume_liters;
            $fromLot = $transfer->fromLot()->firstOrFail();
            $toLot = $transfer->toLot()->firstOrFail();

            if ($transfer->type === CellarTransferType::Rack) {
                // Move it back from the destination vessel to the source vessel.
                if ($transfer->to_vessel_id !== null && $transfer->from_vessel_id !== null) {
                    $fromVessel = Vessel::query()->whereKey($transfer->from_vessel_id)->firstOrFail();
                    $this->volumes->moveWithinLot($fromLot, $transfer->to_vessel_id, $fromVessel, $volume);
                }
            } else {
                // Take it back out of the vessel it went into, return it to the source.
                $toBefore = (float) $toLot->current_volume;
                if ($transfer->to_vessel_id !== null && $toLot->vesselLots()->where('vessel_id', $transfer->to_vessel_id)->exists()) {
                    $this->volumes->adjust($toLot, Quantity::negate($volume), $transfer->to_vessel_id);
                } else {
                    $this->volumes->drain($toLot, $volume);
                }
                if ($transfer->type === CellarTransferType::Blend) {
                    // Undo the grape mix and the cost it carried in.
                    $this->grapes->mix($toLot, $toBefore, $fromLot, -(float) $volume);
                    $toLot->refresh();
                    $toLot->carried_cost = max(0, $toLot->carried_cost - (int) round($this->costs->breakdown($fromLot)['per_liter'] * (float) $volume));
                    $toLot->save();
                }
                /** @var WineLot $fromLot */
                $fromLot = WineLot::query()->whereKey($fromLot->getKey())->lockForUpdate()->firstOrFail();
                $fromLot->current_volume = Quantity::add((string) $fromLot->current_volume, $volume);
                $fromLot->save();
                if ($transfer->from_vessel_id !== null) {
                    $fromVessel = Vessel::query()->whereKey($transfer->from_vessel_id)->firstOrFail();
                    $this->volumes->assignToVessel($fromLot, $fromVessel, $volume);
                }
            }

            $this->audit->record(Auth::user(), 'cellar_transfer.reversed', $transfer, [
                'type' => $transfer->type->value,
                'from_lot_id' => $transfer->from_lot_id,
                'to_lot_id' => $transfer->to_lot_id,
                'volume_liters' => $volume,
            ]);

            $transfer->delete();
        });
    }
}
