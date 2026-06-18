<?php

declare(strict_types=1);

namespace App\Actions\Cellar;

use App\Models\CellarProcess;

/** Delete a cellar process record. */
class DeleteCellarProcessAction
{
    public function execute(CellarProcess $process): void
    {
        $process->delete();
    }
}
