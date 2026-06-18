<?php

declare(strict_types=1);

namespace App\Actions\Cellar;

use App\Models\Vessel;
use Illuminate\Support\Facades\DB;

/**
 * Update cellar-map placement for one or more vessels. Accepts a list of
 * `{ id, position_x?, position_y?, map_width?, map_height?, rotation?, room? }`
 * so drag-and-drop / multi-select moves persist in a single transaction.
 */
class UpdateVesselLayoutAction
{
    /**
     * @param  list<array<string, mixed>>  $updates
     */
    public function execute(array $updates): void
    {
        DB::transaction(function () use ($updates): void {
            foreach ($updates as $update) {
                $vessel = Vessel::query()->whereKey((string) $update['id'])->first();
                if ($vessel === null) {
                    continue;
                }

                $vessel->fill(array_intersect_key($update, array_flip([
                    'position_x', 'position_y', 'map_width', 'map_height', 'rotation', 'room',
                ])));
                $vessel->save();
            }
        });
    }
}
