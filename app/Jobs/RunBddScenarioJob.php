<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\BddRunStatus;
use App\Models\BddScenarioRun;
use App\Services\Bdd\BddRunLog;
use App\Services\Bdd\LiveScenarioRunner;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * Executes one queued BDD run (the AI tool loop) in the background. The run
 * row already exists in QUEUED state, so the UI can poll its live log while
 * this job works; the runner flips it to RUNNING and then to the verdict.
 */
class RunBddScenarioJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /** Comfortably above the runner's own AI wall clock (180s) + overhead. */
    public int $timeout = 300;

    public int $tries = 1;

    public function __construct(public readonly string $runId) {}

    public function handle(LiveScenarioRunner $runner): void
    {
        $run = BddScenarioRun::find($this->runId);

        if ($run !== null) {
            $runner->execute($run);
        }
    }

    /** A dead job must not leave the run (and the polling UI) stuck in flight. */
    public function failed(?Throwable $exception): void
    {
        $run = BddScenarioRun::find($this->runId);

        if ($run === null || ! $run->status->isInFlight()) {
            return;
        }

        $error = 'The run job died: '.($exception?->getMessage() ?? 'unknown failure');

        app(BddRunLog::class)->append($this->runId, $error);
        $run->update(['status' => BddRunStatus::Error, 'error' => $error]);
        $run->scenario?->update(['last_run_status' => BddRunStatus::Error, 'last_run_at' => now()]);
    }
}
