<?php

declare(strict_types=1);

namespace App\Actions\Finance;

use App\Enums\InflowStatus;
use App\Models\Inflow;

class UpdateInflowStatusAction
{
    public function execute(Inflow $inflow, InflowStatus $status): Inflow
    {
        $inflow->status = $status;
        $inflow->received_at = $status === InflowStatus::Received ? ($inflow->received_at ?? now()) : null;
        $inflow->save();

        return $inflow;
    }
}
