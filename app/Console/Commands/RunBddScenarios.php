<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\BddRunStatus;
use App\Queries\Bdd\ListBddScenariosQuery;
use App\Services\Bdd\ScenarioRunner;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

/**
 * Replay saved BDD scenarios (no AI involved — compiled plans only). Exits
 * non-zero when any scenario fails, so this can back a cron or CI gate later;
 * runs stay manual-by-default (no scheduler entry).
 */
class RunBddScenarios extends Command
{
    protected $signature = 'bdd:run {--scenario= : Slug of a single scenario} {--all : Run every active READY scenario}';

    protected $description = 'Run saved BDD scenarios against a rolled-back sandbox tenant';

    public function handle(ScenarioRunner $runner, ListBddScenariosQuery $scenarios): int
    {
        if (($slug = $this->option('scenario')) !== null) {
            $found = $scenarios->findBySlug((string) $slug);
            $list = $found !== null ? new Collection([$found]) : new Collection;
        } elseif ((bool) $this->option('all')) {
            $list = $scenarios->runnable();
        } else {
            $this->error('Pass --scenario=<slug> or --all.');

            return self::FAILURE;
        }

        if ($list->isEmpty()) {
            $this->warn('No runnable scenarios matched.');

            return self::SUCCESS;
        }

        $failed = 0;

        foreach ($list as $scenario) {
            $run = $runner->run($scenario);

            $line = sprintf('%-8s %s (%dms)', $run->status->value, $scenario->title, $run->duration_ms);
            if ($run->status === BddRunStatus::Pass) {
                $this->info($line);
            } else {
                $failed++;
                $this->error($line);
                foreach ($run->step_results ?? [] as $step) {
                    if (($step['status'] ?? '') !== 'pass') {
                        $this->line('         ↳ step '.($step['index'] ?? '?').' ['.($step['op'] ?? '').']: '.($step['detail'] ?? ''));
                    }
                }
                if ($run->error !== null) {
                    $this->line('         ↳ '.$run->error);
                }
            }
        }

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
