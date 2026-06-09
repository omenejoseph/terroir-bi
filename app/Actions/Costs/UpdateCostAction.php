<?php

declare(strict_types=1);

namespace App\Actions\Costs;

use App\Models\Cost;

class UpdateCostAction
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function execute(Cost $cost, array $attributes): Cost
    {
        $cost->fill($attributes)->save();

        return $cost;
    }
}
