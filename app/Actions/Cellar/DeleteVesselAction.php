<?php

declare(strict_types=1);

namespace App\Actions\Cellar;

use App\Models\Vessel;
use Illuminate\Validation\ValidationException;

/** Delete a vessel — only when it holds no wine (no vessel_lots). */
class DeleteVesselAction
{
    public function execute(Vessel $vessel): void
    {
        if ($vessel->vesselLots()->exists()) {
            throw ValidationException::withMessages([
                'vessel' => 'Empty the vessel before deleting it.',
            ]);
        }

        $vessel->delete();
    }
}
