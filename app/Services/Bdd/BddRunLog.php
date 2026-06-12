<?php

declare(strict_types=1);

namespace App\Services\Bdd;

use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Cache;

/**
 * The live progress log of one BDD run, streamed to the UI while the job
 * executes. It lives in the FILE cache store on purpose: the run's whole tool
 * loop happens inside an open (always-rolled-back) DB transaction, so the
 * default `database` cache store would buffer every append inside that
 * transaction — invisible to the polling page and erased by the rollback.
 *
 * The log is ephemeral (short TTL); the finished run persists its final copy
 * on bdd_scenario_runs.logs.
 */
class BddRunLog
{
    private const KEY_PREFIX = 'bdd:run-log:';

    private const TTL_SECONDS = 3600;

    public function start(string $runId): void
    {
        $this->store()->put(self::KEY_PREFIX.$runId, [], self::TTL_SECONDS);
    }

    public function append(string $runId, string $line): void
    {
        $lines = $this->lines($runId);
        $lines[] = '['.now()->format('H:i:s').'] '.$line;

        $this->store()->put(self::KEY_PREFIX.$runId, $lines, self::TTL_SECONDS);
    }

    /**
     * @return list<string>
     */
    public function lines(string $runId): array
    {
        $lines = $this->store()->get(self::KEY_PREFIX.$runId, []);

        return is_array($lines) ? array_values($lines) : [];
    }

    private function store(): Repository
    {
        return Cache::store('file');
    }
}
