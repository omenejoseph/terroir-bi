<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\BddScenario;
use App\Services\Bdd\ScenarioCompiler;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Compiles a BDD scenario's Gherkin into an execution plan asynchronously.
 * Deliberately runs WITHOUT tenant context — the compiler only handles static
 * operation metadata, never tenant data (guard rail).
 */
class CompileBddScenarioJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $timeout = 300;

    public int $tries = 1;

    public function __construct(public readonly string $scenarioId) {}

    public function handle(ScenarioCompiler $compiler): void
    {
        $scenario = BddScenario::find($this->scenarioId);

        if ($scenario !== null) {
            $compiler->compile($scenario);
        }
    }
}
