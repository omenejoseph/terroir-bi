<?php

declare(strict_types=1);

namespace Tests\Feature\Bdd;

use App\Actions\Orders\CreateOrderAction;
use App\Enums\BddRunStatus;
use App\Enums\BddScenarioStatus;
use App\Models\AiUsageLog;
use App\Models\BddOperationGrant;
use App\Models\BddScenario;
use App\Models\BddScenarioRun;
use App\Models\Order;
use App\Models\StockMovement;
use App\Models\Tenant;
use App\Queries\Bdd\BddScenarioRunsQuery;
use App\Services\Ai\AiClient;
use App\Services\Bdd\LiveScenarioRunner;
use App\Services\Bdd\OperationRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\Bdd\FakeBddAiClient;
use Tests\TestCase;

class LiveScenarioRunnerTest extends TestCase
{
    use RefreshDatabase;

    private function scenario(): BddScenario
    {
        return BddScenario::create([
            'title' => 'ORD-001',
            'slug' => 'ord-001-'.uniqid(),
            'gherkin' => "Scenario: Creating an order deducts stock immediately\n  Given \"R3 2025\" has 100 bottles in stock\n  When an order for 24 bottles is created\n  Then current stock is 76 bottles",
            'status' => BddScenarioStatus::Ready,
        ]);
    }

    private function grantCreateOrder(): void
    {
        BddOperationGrant::create([
            'operation_key' => OperationRegistry::ACTION_PREFIX.CreateOrderAction::class,
        ]);
    }

    /**
     * @param  list<array{0: string, 1: array<string, mixed>}>  $script
     * @return array{0: BddScenarioRun, 1: FakeBddAiClient}
     */
    private function runScripted(array $script, ?BddScenario $scenario = null): array
    {
        $ai = new FakeBddAiClient($script);
        $this->app->instance(AiClient::class, $ai);

        $run = app(LiveScenarioRunner::class)->run($scenario ?? $this->scenario());

        return [$run, $ai];
    }

    /**
     * The model's natural tool sequence for ORD-001 (green path).
     *
     * @return list<array{0: string, 1: array<string, mixed>}>
     */
    private function ord001Script(): array
    {
        $action = OperationRegistry::ACTION_PREFIX.CreateOrderAction::class;

        return [
            ['given', ['text' => 'R3 2025 has 100 bottles in stock', 'op' => 'seed.inventory_item',
                'args_json' => json_encode(['name' => 'R3', 'current_stock' => '100']), 'capture' => 'r3']],
            ['given', ['text' => 'a customer exists', 'op' => 'seed.customer', 'args_json' => '{}', 'capture' => 'customer']],
            ['when', ['text' => 'an order for 24 bottles is created', 'op' => $action,
                'args_json' => json_encode(['customer' => '$customer', 'data' => ['items' => [
                    ['inventory_item_id' => '$r3.id', 'quantity' => 24, 'unit_type' => 'bottles'],
                ]]]), 'capture' => 'order']],
            ['probe', ['op' => 'probe.stock_of', 'args_json' => json_encode(['item' => '$r3'])]],
            ['then', ['text' => 'current stock is 76 bottles', 'observed' => '76', 'passed' => true,
                'reason' => 'The probe returned 76, matching the expectation.']],
            ['finish', ['summary' => 'All assertions held.']],
        ];
    }

    public function test_a_green_run_passes_records_steps_and_rolls_back(): void
    {
        $this->grantCreateOrder();
        $scenario = $this->scenario();

        [$run] = $this->runScripted($this->ord001Script(), $scenario);

        $this->assertSame(BddRunStatus::Pass, $run->status, json_encode($run->step_results ?? []) ?: '');
        // given + given + when + probe(info) + then = 5 authoritative rows.
        $steps = $run->step_results ?? [];
        $this->assertCount(5, $steps);
        $this->assertSame('info', $steps[3]['status'] ?? null);
        $this->assertSame('ai.judgement', $steps[4]['op'] ?? null);

        // The full tool transcript (6 calls + the final assistant text) is persisted.
        $transcript = $run->transcript ?? [];
        $this->assertCount(7, $transcript);
        $this->assertSame('given', $transcript[0]['tool'] ?? null);
        $this->assertArrayHasKey('assistant', $transcript[6]);

        // Everything the run created vanished with the rollback…
        $this->assertSame(0, Tenant::query()->where('slug', 'like', 'bdd-sandbox-%')->count());
        $this->assertSame(0, Order::query()->withoutGlobalScopes()->count());
        $this->assertSame(0, StockMovement::query()->withoutGlobalScopes()->count());

        // …but the run record, scenario summary and AI usage log survived.
        $reloaded = BddScenario::query()->whereKey($scenario->getKey())->firstOrFail();
        $this->assertSame(BddRunStatus::Pass, $reloaded->last_run_status);
        $this->assertNotNull($reloaded->last_run_at);
        $this->assertSame(1, AiUsageLog::query()->where('feature', 'bdd_live_run')->count());
    }

    public function test_an_ai_judged_failure_reports_fail_with_the_observation(): void
    {
        $this->grantCreateOrder();
        $script = $this->ord001Script();
        $script[4] = ['then', ['text' => 'current stock is 76 bottles', 'observed' => '100', 'passed' => false,
            'reason' => 'Stock is still 100 — nothing was deducted.']];

        [$run] = $this->runScripted($script);

        $this->assertSame(BddRunStatus::Fail, $run->status);
        $failing = collect($run->step_results ?? [])->firstWhere('status', 'fail');
        $this->assertNotNull($failing);
        $this->assertStringContainsString('100', (string) ($failing['detail'] ?? ''));
    }

    public function test_an_overdraw_expectation_is_matched_factually_in_code(): void
    {
        $this->grantCreateOrder();
        $action = OperationRegistry::ACTION_PREFIX.CreateOrderAction::class;

        [$run] = $this->runScripted([
            ['given', ['text' => '10 bottles in stock', 'op' => 'seed.inventory_item',
                'args_json' => json_encode(['current_stock' => '10']), 'capture' => 'r3']],
            ['given', ['text' => 'a customer', 'op' => 'seed.customer', 'args_json' => '{}', 'capture' => 'customer']],
            ['when', ['text' => 'ordering 24 bottles is rejected', 'op' => $action,
                'args_json' => json_encode(['customer' => '$customer', 'data' => ['items' => [
                    ['inventory_item_id' => '$r3.id', 'quantity' => 24, 'unit_type' => 'bottles'],
                ]]]),
                'expect_error_message_contains' => 'not enough stock']],
            ['probe', ['op' => 'probe.stock_of', 'args_json' => json_encode(['item' => '$r3'])]],
            ['then', ['text' => 'stock is untouched at 10', 'observed' => '10', 'passed' => true,
                'reason' => 'The rejected order deducted nothing.']],
            ['finish', []],
        ]);

        $this->assertSame(BddRunStatus::Pass, $run->status, json_encode($run->step_results ?? []) ?: '');
        $this->assertStringContainsString('expected error', (string) (($run->step_results ?? [])[2]['detail'] ?? ''));
    }

    public function test_a_genuinely_different_error_still_fails_the_expectation(): void
    {
        // Tolerance must not become "any error passes": the overdraw threw
        // InsufficientStockException, but the model expected an email error.
        $this->grantCreateOrder();
        $action = OperationRegistry::ACTION_PREFIX.CreateOrderAction::class;

        [$run] = $this->runScripted([
            ['given', ['text' => '10 in stock', 'op' => 'seed.inventory_item',
                'args_json' => json_encode(['current_stock' => '10']), 'capture' => 'r3']],
            ['given', ['text' => 'a customer', 'op' => 'seed.customer', 'args_json' => '{}', 'capture' => 'customer']],
            ['when', ['text' => 'ordering 24 is rejected', 'op' => $action,
                'args_json' => json_encode(['customer' => '$customer', 'data' => ['items' => [
                    ['inventory_item_id' => '$r3.id', 'quantity' => 24, 'unit_type' => 'bottles'],
                ]]]),
                'expect_error_message_contains' => 'invalid email address']],
            ['finish', []],
        ]);

        $this->assertSame(BddRunStatus::Fail, $run->status);
        $failing = collect($run->step_results ?? [])->firstWhere('status', 'fail');
        $this->assertNotNull($failing);
        $this->assertStringContainsString('mismatch', (string) ($failing['detail'] ?? ''));
    }

    public function test_an_ungranted_action_parks_the_run_as_needs_access(): void
    {
        // No grant for CreateOrderAction.
        $action = OperationRegistry::ACTION_PREFIX.CreateOrderAction::class;
        $scenario = $this->scenario();

        [$run, $ai] = $this->runScripted([
            ['given', ['text' => 'stock', 'op' => 'seed.inventory_item', 'args_json' => '{}', 'capture' => 'r3']],
            ['when', ['text' => 'an order is created', 'op' => $action, 'args_json' => '{}']],
            ['finish', []],
        ], $scenario);

        $this->assertSame(BddRunStatus::NeedsAccess, $run->status);
        $this->assertStringContainsString('CreateOrderAction', (string) $run->error);
        $this->assertStringContainsString('NOT granted', $ai->results[1]);

        // The denied op is sourced from the run's step rows for one-click granting.
        $this->assertSame([$action], app(BddScenarioRunsQuery::class)->latestDeniedOperations($scenario));
    }

    public function test_in_loop_feedback_lets_the_model_self_correct(): void
    {
        // The first `when` references $ghost — the tool returns the error
        // string (recording nothing), the "model" fixes the reference, and the
        // run still goes green: the feedback loop the compiler never had.
        $this->grantCreateOrder();
        $script = $this->ord001Script();
        $broken = $script[2];
        $broken[1]['args_json'] = json_encode(['customer' => '$ghost', 'data' => ['items' => [
            ['inventory_item_id' => '$r3.id', 'quantity' => 24, 'unit_type' => 'bottles'],
        ]]]);
        array_splice($script, 2, 0, [$broken]);

        [$run, $ai] = $this->runScripted($script);

        $this->assertSame(BddRunStatus::Pass, $run->status, json_encode($run->step_results ?? []) ?: '');
        $this->assertStringContainsString('Unknown reference $ghost', $ai->results[2]);
        // The failed attempt recorded no step — only the corrected one did.
        $this->assertCount(1, collect($run->step_results ?? [])->where('keyword', 'when'));
    }

    public function test_an_unexpected_action_error_is_recorded_in_code_regardless_of_the_ai(): void
    {
        // The overdraw throws WITHOUT an expectation → an error step is
        // recorded by code. Even though the scripted "model" then claims the
        // Then passed, the verdict stays Error — infra failures are not AI-judged.
        $this->grantCreateOrder();
        $action = OperationRegistry::ACTION_PREFIX.CreateOrderAction::class;

        [$run] = $this->runScripted([
            ['given', ['text' => '10 in stock', 'op' => 'seed.inventory_item',
                'args_json' => json_encode(['current_stock' => '10']), 'capture' => 'r3']],
            ['given', ['text' => 'a customer', 'op' => 'seed.customer', 'args_json' => '{}', 'capture' => 'customer']],
            ['when', ['text' => 'order 24', 'op' => $action,
                'args_json' => json_encode(['customer' => '$customer', 'data' => ['items' => [
                    ['inventory_item_id' => '$r3.id', 'quantity' => 24, 'unit_type' => 'bottles'],
                ]]])]],
            ['probe', ['op' => 'probe.stock_of', 'args_json' => json_encode(['item' => '$r3'])]],
            ['then', ['text' => 'all good', 'observed' => '10', 'passed' => true, 'reason' => 'optimism']],
            ['finish', []],
        ]);

        $this->assertSame(BddRunStatus::Error, $run->status);
        $this->assertNotNull(collect($run->step_results ?? [])->firstWhere('status', 'error'));
    }

    public function test_a_run_that_judges_nothing_is_an_error_not_a_pass(): void
    {
        $this->grantCreateOrder();

        [$run] = $this->runScripted([
            ['given', ['text' => 'a customer', 'op' => 'seed.customer', 'args_json' => '{}', 'capture' => 'customer']],
            ['finish', []],
        ]);

        $this->assertSame(BddRunStatus::Error, $run->status);
        $this->assertStringContainsString('no Then judgement', (string) $run->error);
    }

    public function test_a_then_without_a_prior_probe_is_rejected(): void
    {
        $this->grantCreateOrder();

        [$run, $ai] = $this->runScripted([
            ['given', ['text' => 'a customer', 'op' => 'seed.customer', 'args_json' => '{}', 'capture' => 'customer']],
            ['then', ['text' => 'everything is fine', 'observed' => 'vibes', 'passed' => true, 'reason' => 'trust me']],
            ['finish', []],
        ]);

        // The judgement was refused (and so never recorded) → the run errors.
        $this->assertSame(BddRunStatus::Error, $run->status);
        $this->assertStringContainsString('run a `probe` first', $ai->results[1]);
    }

    public function test_colon_separated_op_keys_are_canonicalised(): void
    {
        // Models conflate the separators (`seed:` vs `seed.`); live tools
        // normalise instead of failing the call.
        [$run] = $this->runScripted([
            ['given', ['text' => 'stock', 'op' => 'seed:inventory_item',
                'args_json' => json_encode(['current_stock' => '10']), 'capture' => 'r3']],
            ['probe', ['op' => 'probe:stock_of', 'args_json' => json_encode(['item' => '$r3'])]],
            ['then', ['text' => 'stock is 10', 'observed' => '10', 'passed' => true, 'reason' => 'matches']],
            ['finish', []],
        ]);

        $this->assertSame(BddRunStatus::Pass, $run->status, json_encode($run->step_results ?? []) ?: '');
        $this->assertSame('seed.inventory_item', ($run->step_results ?? [])[0]['op'] ?? null);
    }

    public function test_an_entity_param_tolerates_a_dot_id_reference(): void
    {
        // "$customer.id" where the action wants the Customer entity binds to
        // the capture (the invoker's tolerance, carried over from the old runner).
        $this->grantCreateOrder();
        $script = $this->ord001Script();
        [$tool, $arguments] = $script[2];
        $args = json_decode((string) $arguments['args_json'], true);
        $args['customer'] = '$customer.id';
        $arguments['args_json'] = json_encode($args);
        $script[2] = [$tool, $arguments];

        [$run] = $this->runScripted($script);

        $this->assertSame(BddRunStatus::Pass, $run->status, json_encode($run->step_results ?? []) ?: '');
    }

    public function test_running_without_ai_enabled_records_an_error_run(): void
    {
        // No fake bound: the real AiClient reports AI disabled (test default).
        $run = app(LiveScenarioRunner::class)->run($this->scenario());

        $this->assertSame(BddRunStatus::Error, $run->status);
        $this->assertStringContainsString('AI is disabled', (string) $run->error);
        $this->assertInstanceOf(BddScenarioRun::class, $run);
    }
}
