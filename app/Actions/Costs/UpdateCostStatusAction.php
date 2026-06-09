<?php

declare(strict_types=1);

namespace App\Actions\Costs;

use App\Enums\CostStatus;
use App\Models\Cost;

class UpdateCostStatusAction
{
    public function execute(Cost $cost, CostStatus $status): Cost
    {
        $cost->status = $status;
        $cost->paid_at = $status === CostStatus::Paid ? ($cost->paid_at ?? now()) : null;
        $cost->save();

        return $cost;
    }
}
