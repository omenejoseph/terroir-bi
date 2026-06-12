<?php

declare(strict_types=1);

namespace Tests\Feature\Bdd;

use App\Actions\Bdd\GrantBddOperationAction;
use App\Actions\Bdd\SaveBddScenarioAction;
use App\Actions\Orders\CreateOrderAction;
use App\Enums\BddRunStatus;
use App\Enums\BddScenarioStatus;
use App\Jobs\CompileBddScenarioJob;
use App\Models\BddOperationGrant;
use App\Services\Ai\Agents\BddCompilerAgent;
use App\Services\Bdd\OperationRegistry;
use App\Services\Bdd\ScenarioCompiler;
use App\Services\Bdd\ScenarioRunner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ScenarioCompilerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['ai.enabled' => true]);
        // Saving dispatches a compile job; keep it queued so each test drives
        // compilation explicitly against its own faked agent response.
        Queue::fake([CompileBddScenarioJob::class]);
    }

    /**
     * The structured output the faked model returns for ORD-001.
     *
     * @return array<string, mixed>
     */
    private function boundOutput(): array
    {
        $action = OperationRegistry::ACTION_PREFIX.CreateOrderAction::class;

        return [
            'steps' => [
                ['keyword' => 'given', 'text' => 'R3 2025 has 100 bottles in stock', 'op' => 'seed.inventory_item',
                    'args_json' => json_encode(['name' => 'R3', 'current_stock' => '100']), 'capture' => 'r3'],
                ['keyword' => 'given', 'text' => 'a customer', 'op' => 'seed.customer', 'args_json' => '{}', 'capture' => 'customer'],
                ['keyword' => 'when', 'text' => 'order 24 bottles', 'op' => $action,
                    'args_json' => json_encode(['customer' => '$customer', 'data' => ['items' => [
                        ['inventory_item_id' => '$r3.id', 'quantity' => 24, 'unit_type' => 'bottles'],
                    ]]]), 'capture' => 'order'],
                ['keyword' => 'then', 'text' => 'stock is 76', 'op' => 'probe.stock_of',
                    'args_json' => json_encode(['item' => '$r3']), 'assert_json' => json_encode(['equals' => 76])],
            ],
            'unbound' => [],
        ];
    }

    public function test_bound_output_compiles_to_ready_and_the_plan_runs_green(): void
    {
        BddOperationGrant::create(['operation_key' => OperationRegistry::ACTION_PREFIX.CreateOrderAction::class]);
        BddCompilerAgent::fake([$this->boundOutput()]);

        $scenario = app(SaveBddScenarioAction::class)->execute([
            'title' => 'ORD-001 stock deducts at creation',
            'gherkin' => "Scenario: Creating an order deducts stock immediately\n  Given \"R3 2025\" has 100 bottles in stock\n  When a non-backorder order for 24 bottles is created\n  Then current stock is 76 bottles",
        ]);
        Queue::assertPushed(CompileBddScenarioJob::class, 1);

        app(ScenarioCompiler::class)->compile($scenario);

        $scenario->refresh();
        $this->assertSame(BddScenarioStatus::Ready, $scenario->status, (string) $scenario->compile_error);
        $this->assertNotNull($scenario->compiled_plan);

        // The compiled plan replays green end-to-end.
        $run = app(ScenarioRunner::class)->run($scenario);
        $this->assertSame(BddRunStatus::Pass, $run->status, json_encode($run->step_results ?? []) ?: '');
    }

    public function test_unbound_steps_park_the_scenario_as_needs_access(): void
    {
        // No grant for CreateOrderAction → the model reports it as unbound.
        $action = OperationRegistry::ACTION_PREFIX.CreateOrderAction::class;
        BddCompilerAgent::fake([[
            'steps' => [
                ['keyword' => 'given', 'text' => 'stock', 'op' => 'seed.inventory_item', 'args_json' => '{}', 'capture' => 'r3'],
            ],
            'unbound' => [
                ['step_text' => 'When a non-backorder order for 24 bottles is created', 'suggested_operation' => $action, 'why' => 'Creating orders needs this action.'],
            ],
        ]]);

        $scenario = app(SaveBddScenarioAction::class)->execute([
            'title' => 'ORD-001', 'gherkin' => 'Scenario: needs order access',
        ]);
        app(ScenarioCompiler::class)->compile($scenario);

        $scenario->refresh();
        $this->assertSame(BddScenarioStatus::NeedsAccess, $scenario->status);
        $this->assertSame($action, $scenario->requested_operations[0]['suggested_operation'] ?? null);
        $this->assertStringContainsString('CreateOrderAction', (string) $scenario->compile_error);
    }

    public function test_builtin_or_hallucinated_unbound_entries_are_ignored_not_surfaced(): void
    {
        // A confused model flags a built-in seed and a made-up class as "needed".
        // Neither is a real grantable action, so neither should park the scenario.
        BddCompilerAgent::fake([[
            'steps' => [
                ['keyword' => 'given', 'text' => 'a customer', 'op' => 'seed.customer', 'args_json' => '{}', 'capture' => 'customer'],
                ['keyword' => 'then', 'text' => 'no customers counted is wrong but valid op', 'op' => 'probe.db_count',
                    'args_json' => json_encode(['table' => 'customers']), 'assert_json' => json_encode(['gte' => 0])],
            ],
            'unbound' => [
                ['step_text' => 'a customer', 'suggested_operation' => 'seed.customer', 'why' => 'model confusion'],
                ['step_text' => 'magic', 'suggested_operation' => 'action:App\\Actions\\Orders\\TeleportOrderAction', 'why' => 'hallucinated'],
            ],
        ]]);

        $scenario = app(SaveBddScenarioAction::class)->execute(['title' => 'Noise', 'gherkin' => 'Scenario: noise']);
        app(ScenarioCompiler::class)->compile($scenario);

        // The noise is dropped; the bindable plan compiles READY.
        $scenario->refresh();
        $this->assertSame(BddScenarioStatus::Ready, $scenario->status, (string) $scenario->compile_error);
        $this->assertNull($scenario->requested_operations);
    }

    public function test_colon_separated_seed_and_probe_ops_are_canonicalised_not_parked(): void
    {
        // The model conflated the action separator (action:) with the seed/probe
        // one (.), emitting `seed:` and `probe:`. Left raw these read as unknown,
        // ungranted ops and park the scenario in NEEDS_ACCESS (the ORD-002 bug).
        BddCompilerAgent::fake([[
            'steps' => [
                ['keyword' => 'given', 'text' => 'stock', 'op' => 'seed:inventory_item',
                    'args_json' => json_encode(['current_stock' => '10']), 'capture' => 'r3'],
                ['keyword' => 'then', 'text' => 'stock is 10', 'op' => 'probe:stock_of',
                    'args_json' => json_encode(['item' => '$r3']), 'assert_json' => json_encode(['equals' => 10])],
                ['keyword' => 'then', 'text' => 'no movements', 'op' => 'probe:db_count',
                    'args_json' => json_encode(['table' => 'stock_movements']), 'assert_json' => json_encode(['equals' => 0])],
            ],
            'unbound' => [],
        ]]);

        $scenario = app(SaveBddScenarioAction::class)->execute([
            'title' => 'ORD-002 separator slip', 'gherkin' => 'Scenario: overdraw guard',
        ]);
        app(ScenarioCompiler::class)->compile($scenario);

        $scenario->refresh();
        $this->assertSame(BddScenarioStatus::Ready, $scenario->status, (string) $scenario->compile_error);
        $this->assertNull($scenario->requested_operations);
        $this->assertSame(
            ['seed.inventory_item', 'probe.stock_of', 'probe.db_count'],
            array_column($scenario->compiled_plan['steps'], 'op'),
        );

        // And the canonicalised plan replays green.
        $this->assertSame(BddRunStatus::Pass, app(ScenarioRunner::class)->run($scenario)->status);
    }

    public function test_an_already_granted_action_listed_as_unbound_does_not_park_the_scenario(): void
    {
        // The model bound CreateOrderAction in a step AND redundantly listed it
        // as unbound. It is already granted, so the entry is noise — the scenario
        // must compile READY, not park asking for access it already has (which
        // would loop on every recompile).
        $action = OperationRegistry::ACTION_PREFIX.CreateOrderAction::class;
        BddOperationGrant::create(['operation_key' => $action]);
        $output = $this->boundOutput();
        $output['unbound'] = [
            ['step_text' => 'an order is submitted', 'suggested_operation' => $action, 'why' => null],
        ];
        BddCompilerAgent::fake([$output]);

        $scenario = app(SaveBddScenarioAction::class)->execute([
            'title' => 'ORD-002 redundant unbound', 'gherkin' => 'Scenario: overdraw guard',
        ]);
        app(ScenarioCompiler::class)->compile($scenario);

        $scenario->refresh();
        $this->assertSame(BddScenarioStatus::Ready, $scenario->status, (string) $scenario->compile_error);
        $this->assertNull($scenario->requested_operations);
    }

    public function test_expect_error_with_a_quoted_message_survives_the_decode(): void
    {
        // A JSON blob broke here ("expect_error_json is not valid JSON") whenever
        // the message carried a quote or em-dash. On flat string fields the same
        // text rides through verbatim — no escaping, no parse step to fail.
        $agent = app(BddCompilerAgent::class);

        $result = $agent->toPlan(['steps' => [[
            'keyword' => 'when', 'text' => 'overdraw is rejected', 'op' => 'action:App\\Actions\\Orders\\CreateOrderAction',
            'args_json' => '{}',
            'expect_error_class' => 'InsufficientStockException',
            'expect_error_message_contains' => 'Not enough stock for "Plavac" — 5.000 bottles available',
        ]], 'unbound' => []]);

        $this->assertSame([], $result['errors']);
        $this->assertSame(
            ['class' => 'InsufficientStockException', 'message_contains' => 'Not enough stock for "Plavac" — 5.000 bottles available'],
            $result['plan']['steps'][0]['expect_error'],
        );
    }

    public function test_an_overdraw_scenario_compiles_and_replays_green_via_expect_error(): void
    {
        BddOperationGrant::create(['operation_key' => OperationRegistry::ACTION_PREFIX.CreateOrderAction::class]);
        $action = OperationRegistry::ACTION_PREFIX.CreateOrderAction::class;
        BddCompilerAgent::fake([[
            'steps' => [
                ['keyword' => 'given', 'text' => 'stock', 'op' => 'seed.inventory_item',
                    'args_json' => json_encode(['name' => 'Plavac', 'current_stock' => '5']), 'capture' => 'r3'],
                ['keyword' => 'given', 'text' => 'a customer', 'op' => 'seed.customer', 'args_json' => '{}', 'capture' => 'customer'],
                ['keyword' => 'when', 'text' => 'ordering 12 is rejected', 'op' => $action,
                    'args_json' => json_encode(['customer' => '$customer', 'data' => ['items' => [
                        ['inventory_item_id' => '$r3.id', 'quantity' => 12, 'unit_type' => 'bottles'],
                    ]]]),
                    'expect_error_class' => 'InsufficientStockException',
                    'expect_error_message_contains' => 'Not enough stock'],
                ['keyword' => 'then', 'text' => 'stock untouched', 'op' => 'probe.stock_of',
                    'args_json' => json_encode(['item' => '$r3']), 'assert_json' => json_encode(['equals' => 5])],
            ],
            'unbound' => [],
        ]]);

        $scenario = app(SaveBddScenarioAction::class)->execute([
            'title' => 'ORD-002 overdraw', 'gherkin' => 'Scenario: overdraw guard',
        ]);
        app(ScenarioCompiler::class)->compile($scenario);

        $scenario->refresh();
        $this->assertSame(BddScenarioStatus::Ready, $scenario->status, (string) $scenario->compile_error);
        $this->assertSame(BddRunStatus::Pass, app(ScenarioRunner::class)->run($scenario)->status);
    }

    public function test_an_invalid_first_attempt_is_self_corrected_on_retry(): void
    {
        BddOperationGrant::create(['operation_key' => OperationRegistry::ACTION_PREFIX.CreateOrderAction::class]);
        $action = OperationRegistry::ACTION_PREFIX.CreateOrderAction::class;

        // Attempt 1: the model forgot to seed a customer → $customer is undefined.
        $invalid = [
            'steps' => [
                ['keyword' => 'given', 'text' => 'stock', 'op' => 'seed.inventory_item',
                    'args_json' => json_encode(['current_stock' => '100']), 'capture' => 'r3'],
                ['keyword' => 'when', 'text' => 'order 24', 'op' => $action,
                    'args_json' => json_encode(['customer' => '$customer', 'data' => ['items' => [
                        ['inventory_item_id' => '$r3.id', 'quantity' => 24, 'unit_type' => 'bottles'],
                    ]]]), 'capture' => 'order'],
                ['keyword' => 'then', 'text' => 'stock 76', 'op' => 'probe.stock_of',
                    'args_json' => json_encode(['item' => '$r3']), 'assert_json' => json_encode(['equals' => 76])],
            ],
            'unbound' => [],
        ];
        // Attempt 2 (after the error feedback): adds the seed.customer step.
        $corrected = $this->boundOutput();
        array_splice($corrected['steps'], 1, 0, [[
            'keyword' => 'given', 'text' => 'a customer', 'op' => 'seed.customer', 'args_json' => '{}', 'capture' => 'customer',
        ]]);
        $corrected['steps'][2]['args_json'] = json_encode(['customer' => '$customer', 'data' => ['items' => [
            ['inventory_item_id' => '$r3.id', 'quantity' => 24, 'unit_type' => 'bottles'],
        ]]]);

        BddCompilerAgent::fake([$invalid, $corrected]);

        $scenario = app(SaveBddScenarioAction::class)->execute(['title' => 'ORD-001', 'gherkin' => 'Scenario: self-correct']);
        app(ScenarioCompiler::class)->compile($scenario);

        // The retry fixed the undefined reference → READY, and the plan runs green.
        $scenario->refresh();
        $this->assertSame(BddScenarioStatus::Ready, $scenario->status, (string) $scenario->compile_error);
        $this->assertSame(BddRunStatus::Pass, app(ScenarioRunner::class)->run($scenario)->status);
    }

    public function test_toplan_accepts_object_form_and_fenced_json_for_the_json_fields(): void
    {
        $agent = app(BddCompilerAgent::class);

        $result = $agent->toPlan(['steps' => [
            // args as a real nested object (not a JSON string) + assert as object.
            ['keyword' => 'given', 'text' => 'stock', 'op' => 'seed.inventory_item',
                'args_json' => ['current_stock' => '100'], 'capture' => 'r3'],
            // args as a ```json fenced string.
            ['keyword' => 'then', 'text' => 'stock', 'op' => 'probe.stock_of',
                'args_json' => "```json\n{\"item\": \"\$r3\"}\n```", 'assert_json' => ['equals' => 100]],
        ], 'unbound' => []]);

        $this->assertSame([], $result['errors']);
        $this->assertSame(['current_stock' => '100'], $result['plan']['steps'][0]['args']);
        $this->assertSame(['item' => '$r3'], $result['plan']['steps'][1]['args']);
        $this->assertSame(['equals' => 100], $result['plan']['steps'][1]['assert']);
    }

    public function test_compiler_output_binding_ungranted_ops_is_caught_by_validation(): void
    {
        // The model claims it bound the action even though it is NOT granted.
        BddCompilerAgent::fake([$this->boundOutput()]);

        $scenario = app(SaveBddScenarioAction::class)->execute([
            'title' => 'ORD-001', 'gherkin' => 'Scenario: defense in depth',
        ]);
        app(ScenarioCompiler::class)->compile($scenario);

        $this->assertSame(BddScenarioStatus::NeedsAccess, $scenario->refresh()->status);
    }

    public function test_granting_the_requested_operation_recompiles_to_ready(): void
    {
        $action = OperationRegistry::ACTION_PREFIX.CreateOrderAction::class;
        BddCompilerAgent::fake([[
            'steps' => [],
            'unbound' => [['step_text' => 'When …', 'suggested_operation' => $action, 'why' => null]],
        ]]);

        $scenario = app(SaveBddScenarioAction::class)->execute(['title' => 'ORD-001', 'gherkin' => 'Scenario: x']);
        app(ScenarioCompiler::class)->compile($scenario);
        $this->assertSame(BddScenarioStatus::NeedsAccess, $scenario->refresh()->status);

        // Granting queues a recompile for the parked scenario…
        Queue::fake([CompileBddScenarioJob::class]);
        app(GrantBddOperationAction::class)->execute($action);
        Queue::assertPushed(CompileBddScenarioJob::class, fn (CompileBddScenarioJob $job) => $job->scenarioId === $scenario->getKey());

        // …and with access in place the model's bound output now compiles READY.
        BddCompilerAgent::fake([$this->boundOutput()]);
        app(ScenarioCompiler::class)->compile($scenario->refresh());
        $this->assertSame(BddScenarioStatus::Ready, $scenario->refresh()->status, (string) $scenario->compile_error);
    }
}
