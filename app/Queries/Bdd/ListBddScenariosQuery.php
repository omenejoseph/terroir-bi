<?php

declare(strict_types=1);

namespace App\Queries\Bdd;

use App\Models\BddScenario;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Scenario reads for the back office. Exposing the builder lets Filament drive
 * pagination/sorting while keeping the DB query behind a class; `runnable()`
 * returns the active scenarios for "Run all" and the CLI (live execution needs
 * only the Gherkin — there is no compile gate).
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
     * Active scenarios, title-ordered.
     *
     * @return Collection<int, BddScenario>
     */
    public function runnable(): Collection
    {
        return BddScenario::query()
            ->where('is_active', true)
            ->orderBy('title')
            ->get();
    }

    public function findBySlug(string $slug): ?BddScenario
    {
        return BddScenario::query()
            ->where('slug', $slug)
            ->first();
    }
}
