<?php

declare(strict_types=1);

namespace App\Queries\Bdd;

use App\Enums\BddScenarioStatus;
use App\Models\BddScenario;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Scenario reads for the back office. Exposing the builder lets Filament drive
 * pagination/sorting while keeping the DB query behind a class; `runnable()`
 * returns the active, compiled scenarios for "Run all" and the CLI.
 */
class ListBddScenariosQuery
{
    /**
     * @return Builder<BddScenario>
     */
    public function builder(): Builder
    {
        return BddScenario::query();
    }

    /**
     * Active scenarios with a runnable compiled plan, title-ordered.
     *
     * @return Collection<int, BddScenario>
     */
    public function runnable(): Collection
    {
        return BddScenario::query()
            ->where('status', BddScenarioStatus::Ready->value)
            ->where('is_active', true)
            ->orderBy('title')
            ->get();
    }

    public function findBySlug(string $slug): ?BddScenario
    {
        return BddScenario::query()
            ->where('status', BddScenarioStatus::Ready->value)
            ->where('slug', $slug)
            ->first();
    }
}
