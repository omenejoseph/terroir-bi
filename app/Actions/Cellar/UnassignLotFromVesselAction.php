<?php

declare(strict_types=1);

namespace App\Actions\Cellar;

use App\Models\VesselLot;
use App\Models\WineLot;
use App\Services\Audit\AuditLogger;
use App\Services\Cellar\LotVolumeService;
use Illuminate\Support\Facades\Auth;

/** Remove a lot's presence from a vessel and resync that vessel. */
class UnassignLotFromVesselAction
{
    public function __construct(
        private readonly LotVolumeService $volumes,
        private readonly AuditLogger $audit,
    ) {}

    public function execute(VesselLot $vesselLot): void
    {
        $lot = WineLot::query()->whereKey($vesselLot->wine_lot_id)->first();
        $metadata = ['vessel_id' => $vesselLot->vessel_id, 'volume' => (string) $vesselLot->volume];

        $this->volumes->unassign($vesselLot);

        $this->audit->record(Auth::user(), 'wine_lot.unassigned_from_vessel', $lot, $metadata);
    }
}
