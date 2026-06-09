<?php

declare(strict_types=1);

namespace App\Actions\Finance;

use App\Enums\InflowStatus;
use App\Models\Inflow;

class CreateInflowAction
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function execute(array $attributes, string $createdById): Inflow
    {
        $attributes['created_by_id'] = $createdById;
        $attributes['date'] ??= now();

        $inflow = Inflow::create($attributes);

        // A record created as RECEIVED is stamped received now.
        if ($inflow->status === InflowStatus::Received && $inflow->received_at === null) {
            $inflow->received_at = now();
            $inflow->save();
        }

        return $inflow;
    }
}
