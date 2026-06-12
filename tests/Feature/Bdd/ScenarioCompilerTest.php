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
