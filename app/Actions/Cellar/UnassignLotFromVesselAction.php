<?php

declare(strict_types=1);

namespace App\Actions\Cellar;

use App\Models\VesselLot;
use App\Services\Cellar\LotVolumeService;

/** Remove a lot's presence from a vessel and resync that vessel. */
class UnassignLotFromVesselAction
{
    public function __construct(private readonly LotVolumeService $volumes) {}

    public function execute(VesselLot $vesselLot): void
    {
        $this->volumes->unassign($vesselLot);
    }
}
