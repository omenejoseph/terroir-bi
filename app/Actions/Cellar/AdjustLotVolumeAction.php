<?php

declare(strict_types=1);

namespace App\Actions\Cellar;

use App\Models\WineLot;
use App\Services\Cellar\LotVolumeService;

/** Adjust a lot's volume (loss, top-up, correction) and keep vessels in sync. */
class AdjustLotVolumeAction
{
    public function __construct(private readonly LotVolumeService $volumes) {}

    public function execute(WineLot $lot, string $delta, ?string $vesselId = null): WineLot
    {
        return $this->volumes->adjust($lot, $delta, $vesselId);
    }
}
