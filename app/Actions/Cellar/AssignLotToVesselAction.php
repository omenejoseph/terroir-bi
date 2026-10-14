<?php

declare(strict_types=1);

namespace App\Actions\Cellar;

use App\Models\Vessel;
use App\Models\VesselLot;
use App\Models\WineLot;
use App\Services\Audit\AuditLogger;
use App\Services\Cellar\LotVolumeService;
use Illuminate\Support\Facades\Auth;

/** Place some of a lot's volume into a vessel (capacity-guarded). */
class AssignLotToVesselAction
{
    public function __construct(
        private readonly LotVolumeService $volumes,
        private readonly AuditLogger $audit,
    ) {}

    public function execute(WineLot $lot, Vessel $vessel, string $volume): VesselLot
    {
        $vesselLot = $this->volumes->assignToVessel($lot, $vessel, $volume);

        $this->audit->record(Auth::user(), 'wine_lot.assigned_to_vessel', $lot, [
            'vessel_id' => $vessel->getKey(),
            'vessel_name' => $vessel->name,
            'volume' => $volume,
        ]);

        return $vesselLot;
    }
}
