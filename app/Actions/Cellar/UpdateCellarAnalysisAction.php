<?php

declare(strict_types=1);

namespace App\Actions\Cellar;

use App\Models\CellarAnalysis;

/** Edit an existing analysis. */
class UpdateCellarAnalysisAction
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function execute(CellarAnalysis $analysis, array $data): CellarAnalysis
    {
        $keys = ['vessel_id', 'date', 'note', ...CellarAnalysis::PARAMETERS];
        $analysis->fill(array_intersect_key($data, array_flip($keys)));
        $analysis->save();

        return $analysis;
    }
}
