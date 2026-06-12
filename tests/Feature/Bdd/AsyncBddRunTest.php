<?php

declare(strict_types=1);

namespace Tests\Feature\Bdd;

use App\Enums\BddRunStatus;
use App\Enums\BddScenarioStatus;
use App\Jobs\RunBddScenarioJob;
use App\Models\BddScenario;
use App\Models\BddScenarioRun;
use App\Services\Ai\AiClient;
use App\Services\Bdd\BddRunLog;
use App\Services\Bdd\LiveScenarioRunner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\Support\Bdd\FakeBddAiClient;
use Tests\TestCase;

class AsyncBddRunTest extends TestCase
{
    use RefreshDatabase;

    private function scenario(): BddScenario
    {
        return BddScenario::create([
            'title' => 'ORD-async',
            'slug' => 'ord-async-'.uniqid(),
            'gherkin' => "Scenario: a customer exists\n  Given a customer\n  Then one customer is counted",
            'status' => BddScenarioStatus::Ready,
        ]);
    }

    /**
     * A grant-free script: seed, probe, judge, finish.
     *
     * @return list<array{0: string, 1: array<string, mixed>}>
     */
    private function script(): array
    {
        return [
            ['given', ['text' => 'a customer', 'op' => 'seed.customer', 'args_json' => '{}', 'capture' => 'customer']],
            ['probe', ['op' => 'probe.db_count', 'args_json' => json_encode(['table' => 'customers'])]],
            ['then', ['text' => 'one customer is counted', 'observed' => '1', 'passed' => true,
                'reason' => 'The probe counted exactly one customer row.']],
            ['finish', []],
        ];
    }

    public function test_queue_creates_a_queued_run_and_dispatches_the_job(): void
    {
        Queue::fake([RunBddScenarioJob::class]);
        $scenario = $this->scenario();

        $run = app(LiveScenarioRunner::class)->queue($scenario, null);

        $this->assertSame(BddRunStatus::Queued, $run->status);
        $this->assertSame(BddRunStatus::Queued, $scenario->refresh()->last_run_status);
        Queue::assertPushed(RunBddScenarioJob::class, fn (RunBddScenarioJob $job): bool => $job->runId === (string) $run->getKey());

        // The live log is already pollable before any worker picks the job up.
        $this->assertNotEmpty(app(BddRunLog::class)->lines((string) $run->getKey()));
    }

    public function test_the_job_executes_the_run_and_persists_the_streamed_log(): void
    {
        Queue::fake([RunBddScenarioJob::class]);
        $this->app->instance(AiClient::class, new FakeBddAiClient($this->script()));

        $run = app(LiveScenarioRunner::class)->queue($this->scenario(), null);
        (new RunBddScenarioJob((string) $run->getKey()))->handle(app(LiveScenarioRunner::class));

        $run->refresh();
        $this->assertSame(BddRunStatus::Pass, $run->status, json_encode($run->step_results ?? []) ?: '');

        // The log the UI streamed while polling is saved with the run: queue →
        // start → every tool call/result → verdict.
        $logs = implode("\n", $run->logs ?? []);
        $this->assertStringContainsString('Run queued', $logs);
        $this->assertStringContainsString('Run started', $logs);
        $this->assertStringContainsString('▶ given', $logs);
        $this->assertStringContainsString('▶ then', $logs);
        $this->assertStringContainsString('Sandbox rolled back', $logs);
        $this->assertStringContainsString('Verdict: PASS', $logs);

        // …and the same lines are still live-readable from the cache log.
        $this->assertNotEmpty(app(BddRunLog::class)->lines((string) $run->getKey()));
    }

    public function test_executing_a_finished_run_is_a_no_op(): void
    {
        // A double-dispatched job must not re-run (and re-bill) a finished run.
        Queue::fake([RunBddScenarioJob::class]);
        $this->app->instance(AiClient::class, new FakeBddAiClient($this->script()));

        $run = app(LiveScenarioRunner::class)->queue($this->scenario(), null);
        (new RunBddScenarioJob((string) $run->getKey()))->handle(app(LiveScenarioRunner::class));
        $finishedAt = $run->refresh()->updated_at;
        $this->assertNotNull($finishedAt);

        (new RunBddScenarioJob((string) $run->getKey()))->handle(app(LiveScenarioRunner::class));

        $run->refresh();
        $this->assertSame(BddRunStatus::Pass, $run->status);
        $this->assertTrue($run->updated_at?->equalTo($finishedAt) ?? false);
    }

    public function test_a_dead_job_marks_the_run_as_error_instead_of_stuck_in_flight(): void
    {
        Queue::fake([RunBddScenarioJob::class]);
        $scenario = $this->scenario();
        $run = app(LiveScenarioRunner::class)->queue($scenario, null);

        (new RunBddScenarioJob((string) $run->getKey()))->failed(new RuntimeException('worker out of memory'));

        $run->refresh();
        $this->assertSame(BddRunStatus::Error, $run->status);
        $this->assertStringContainsString('worker out of memory', (string) $run->error);
        $this->assertSame(BddRunStatus::Error, $scenario->refresh()->last_run_status);
    }

    public function test_a_failed_callback_after_the_run_finished_changes_nothing(): void
    {
        // failed() can fire after a successful handle() in edge cases (e.g. a
        // timeout signal racing completion) — a finished verdict must stand.
        Queue::fake([RunBddScenarioJob::class]);
        $this->app->instance(AiClient::class, new FakeBddAiClient($this->script()));

        $run = app(LiveScenarioRunner::class)->queue($this->scenario(), null);
        (new RunBddScenarioJob((string) $run->getKey()))->handle(app(LiveScenarioRunner::class));

        (new RunBddScenarioJob((string) $run->getKey()))->failed(new RuntimeException('late signal'));

        $this->assertSame(BddRunStatus::Pass, $run->refresh()->status);
    }

    public function test_run_rows_are_visible_to_pollers_while_in_flight(): void
    {
        // The QUEUED row (what the page polls) exists before execution begins.
        Queue::fake([RunBddScenarioJob::class]);
        $run = app(LiveScenarioRunner::class)->queue($this->scenario(), null);

        $persisted = BddScenarioRun::query()->whereKey($run->getKey())->firstOrFail();
        $this->assertSame(BddRunStatus::Queued, $persisted->status);
        $this->assertTrue($persisted->status->isInFlight());
    }
}
