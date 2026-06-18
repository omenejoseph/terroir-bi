<?php

declare(strict_types=1);

namespace App\Actions\Cellar;

use App\Models\Vessel;
use App\Models\VesselLot;
use App\Models\WineLot;
use App\Services\Cellar\LotVolumeService;

/** Place some of a lot's volume into a vessel (capacity-guarded). */
class AssignLotToVesselAction
{
    public function __construct(private readonly LotVolumeService $volumes) {}

    public function execute(WineLot $lot, Vessel $vessel, string $volume): VesselLot
    {
        return $this->volumes->assignToVessel($lot, $vessel, $volume);
    }
}
