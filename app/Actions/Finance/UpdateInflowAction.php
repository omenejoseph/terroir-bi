<?php

declare(strict_types=1);

namespace App\Actions\Finance;

use App\Models\Inflow;

class UpdateInflowAction
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function execute(Inflow $inflow, array $attributes): Inflow
    {
        $inflow->fill($attributes)->save();

        return $inflow;
    }
}
