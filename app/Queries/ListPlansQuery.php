<?php

declare(strict_types=1);

namespace App\Queries;

use App\Models\Plan;
use Illuminate\Database\Eloquent\Builder;

/**
 * Plans for the back office. Exposing the builder (not a result) lets Filament's
 * table take over pagination/sorting while keeping the DB query behind a class.
 */
class ListPlansQuery
{
    /**
     * @return Builder<Plan>
     */
    public function builder(): Builder
    {
        return Plan::query()->withCount('tenants');
    }

    /**
     * Plan id → name, for back-office select inputs (keeps the DB read here).
     *
     * @return array<string, string>
     */
    public function options(): array
    {
        return Plan::query()->orderBy('name')->pluck('name', 'id')->all();
    }
}
