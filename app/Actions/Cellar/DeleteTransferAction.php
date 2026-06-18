<?php

declare(strict_types=1);

namespace App\Actions\Cellar;

use App\Enums\CellarTransferType;
use App\Models\CellarTransfer;
use App\Models\Vessel;
use App\Models\WineLot;
use App\Services\Cellar\LotVolumeService;
use App\Support\Quantity;
use Illuminate\Support\Facades\DB;

/** Reverse a transfer (inverse of CreateTransferAction), then delete the record. */
class DeleteTransferAction
{
    public function __construct(private readonly LotVolumeService $volumes) {}

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
                // Drain the destination, return volume to the source.
                $this->volumes->drain($toLot, $volume);
                /** @var WineLot $fromLot */
                $fromLot = WineLot::query()->whereKey($fromLot->getKey())->lockForUpdate()->firstOrFail();
                $fromLot->current_volume = Quantity::add((string) $fromLot->current_volume, $volume);
                $fromLot->save();
                if ($transfer->from_vessel_id !== null) {
                    $fromVessel = Vessel::query()->whereKey($transfer->from_vessel_id)->firstOrFail();
                    $this->volumes->assignToVessel($fromLot, $fromVessel, $volume);
                }
            }

            $transfer->delete();
        });
    }
}
