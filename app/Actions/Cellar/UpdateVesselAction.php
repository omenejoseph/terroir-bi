<?php

declare(strict_types=1);

namespace App\Actions\Cellar;

use App\Models\Vessel;

/** Update a vessel's descriptive fields (not its derived volume). */
class UpdateVesselAction
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function execute(Vessel $vessel, array $data): Vessel
    {
        $vessel->fill(array_intersect_key($data, array_flip([
            'name', 'type', 'material', 'capacity_liters', 'location', 'status',
            'is_active', 'is_faulty', 'fault_note', 'room', 'notes',
        ])));
        $vessel->save();

        return $vessel;
    }
}
