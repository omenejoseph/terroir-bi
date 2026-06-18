<?php

declare(strict_types=1);

namespace App\Actions\Cellar;

use App\Models\Vessel;

/** Create a single cellar vessel. */
class CreateVesselAction
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function execute(array $data): Vessel
    {
        return Vessel::create([
            'name' => $data['name'],
            'type' => $data['type'],
            'material' => $data['material'] ?? null,
            'capacity_liters' => $data['capacity_liters'],
            'location' => $data['location'] ?? null,
            'room' => $data['room'] ?? 'Main Cellar',
            'is_faulty' => (bool) ($data['is_faulty'] ?? false),
            'fault_note' => $data['fault_note'] ?? null,
            'notes' => $data['notes'] ?? null,
        ]);
    }
}
