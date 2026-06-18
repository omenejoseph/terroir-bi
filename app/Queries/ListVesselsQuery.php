<?php

declare(strict_types=1);

namespace App\Queries;

use App\Models\Vessel;
use Illuminate\Database\Eloquent\Collection;

/**
 * Vessel listing for the cellar map. Eager-loads each vessel's lots (and the lot
 * summary) so the map can render fill levels and wine identity in one round trip.
 */
class ListVesselsQuery
{
    /**
     * @param  array{room?: ?string, active_only?: ?bool}  $filters
     * @return Collection<int, Vessel>
     */
    public function get(array $filters = []): Collection
    {
        $query = Vessel::query()->with(['vesselLots.wineLot']);

        if (! empty($filters['room'])) {
            $query->where('room', $filters['room']);
        }

        if (! empty($filters['active_only'])) {
            $query->where('is_active', true);
        }

        return $query->orderBy('room')->orderBy('name')->get();
    }
}
