<?php

declare(strict_types=1);

namespace App\Actions\Cellar;

use App\Models\CellarAnalysis;

/** Delete an analysis. */
class DeleteCellarAnalysisAction
{
    public function execute(CellarAnalysis $analysis): void
    {
        $analysis->delete();
    }
}
