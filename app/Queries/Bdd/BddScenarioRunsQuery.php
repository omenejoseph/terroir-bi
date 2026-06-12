<?php

declare(strict_types=1);

namespace App\Queries\Bdd;

use App\Models\BddScenario;
use App\Models\BddScenarioRun;

/**
 * Run-history reads for a scenario — keeps the relationship queries the view
 * needs out of the Filament schema (DB access stays behind a class).
 */
class BddScenarioRunsQuery
{
    public function hasRuns(BddScenario $scenario): bool
    {
        return BddScenarioRun::query()
            ->where('bdd_scenario_id', $scenario->getKey())
            ->exists();
    }

    public function latest(BddScenario $scenario): ?BddScenarioRun
    {
        return BddScenarioRun::query()
            ->where('bdd_scenario_id', $scenario->getKey())
            ->latest()
            ->first();
    }
}
