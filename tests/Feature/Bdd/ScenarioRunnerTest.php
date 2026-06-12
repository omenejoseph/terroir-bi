<?php

declare(strict_types=1);

namespace Tests\Feature\Bdd;

use App\Actions\Orders\CreateOrderAction;
use App\Enums\BddRunStatus;
use App\Enums\BddScenarioStatus;
use App\Models\BddOperationGrant;
use App\Models\BddScenario;
use App\Models\Order;
use App\Models\StockMovement;
use App\Models\Tenant;
use App\Services\Bdd\OperationRegistry;
use App\Services\Bdd\ScenarioRunner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ScenarioRunnerTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @param  array<string, mixed>  $plan
     */
    private function scenario(array $plan, BddScenarioStatus $status = BddScenarioStatus::Ready): BddScenario
    {
        return BddScenario::create([
            'title' => 'ORD-001',
            'slug' => 'ord-001-'.uniqid(),
            'gherkin' => 'Scenario: stock deducts on creation',
            'status' => $status,
            'compiled_plan' => $plan,
        ]);
    }

    private function grantCreateOrder(): void
    {
        BddOperationGrant::create([
            'operation_key' => OperationRegistry::ACTION_PREFIX.CreateOrderAction::class,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function ord001Plan(): array
    {
        return ['version' => 1, 'steps' => [
            ['keyword' => 'given', 'text' => 'R3 2025 has 100 bottles in stock',
                'op' => 'seed.inventory_item', 'args' => ['name' => 'R3', 'current_stock' => '100'], 'capture' => 'r3'],
            ['keyword' => 'given', 'text' => 'a customer exists',
                'op' => 'seed.customer', 'args' => [], 'capture' => 'customer'],
            ['keyword' => 'when', 'text' => 'an order for 24 bottles is created',
                'op' => OperationRegistry::ACTION_PREFIX.CreateOrderAction::class,
                'args' => ['customer' => '$customer', 'data' => [
                    'items' => [['inventory_item_id' => '$r3.id', 'quantity' => 24, 'unit_type' => 'bottles']],
                ]], 'capture' => 'order'],
            ['keyword' => 'then', 'text' => 'stock is 76',
                'op' => 'probe.stock_of', 'args' => ['item' => '$r3'], 'assert' => ['equals' => 76]],
            ['keyword' => 'then', 'text' => 'the deduct references the order number',
                'op' => 'probe.movements_of', 'args' => ['item' => '$r3'],
                'assert' => ['path' => '0.reference', 'equals_ref' => '$order.order_number']],
        ]];
    }

    public function test_a_compiled_ord001_plan_passes_and_rolls_back(): void
    {
        $this->grantCreateOrder();
        $scenario = $this->scenario($this->ord001Plan());

        $run = app(ScenarioRunner::class)->run($scenario);

        $this->assertSame(BddRunStatus::Pass, $run->status, json_encode($run->step_results ?? []) ?: '');
        $this->assertCount(5, $run->step_results ?? []);

        // Everything the run created vanished with the rollback.
        $this->assertSame(0, Tenant::query()->where('slug', 'like', 'bdd-sandbox-%')->count());
        $this->assertSame(0, Order::query()->withoutGlobalScopes()->count());
        $this->assertSame(0, StockMovement::query()->withoutGlobalScopes()->count());

        // …but the run record and scenario summary survived.
        $reloaded = BddScenario::query()->whereKey($scenario->getKey())->firstOrFail();
        $this->assertSame(BddRunStatus::Pass, $reloaded->last_run_status);
        $this->assertNotNull($reloaded->last_run_at);
    }

    public function test_a_blank_operator_id_from_the_model_is_auto_filled_not_inserted_empty(): void
    {
        // The model emitted an empty createdById (the reported FK failure). The
        // runner must substitute the sandbox operator, not insert "".
        $this->grantCreateOrder();
        $plan = $this->ord001Plan();
        $plan['steps'][2]['args']['createdById'] = ''; // blank, as the model sent

        $run = app(ScenarioRunner::class)->run($this->scenario($plan));

        $this->assertSame(BddRunStatus::Pass, $run->status, json_encode($run->step_results ?? []) ?: '');
    }

    public function test_contains_matches_a_movement_row_by_partial_object_with_a_ref(): void
    {
        // The natural assertion a model writes for "an ORDER_DEDUCT of -24
        // references the order": contains a partial object, with the reference
        // as a $capture reference into the order.
        $this->grantCreateOrder();
        $plan = $this->ord001Plan();
        $plan['steps'][4] = [
            'keyword' => 'then', 'text' => 'an ORDER_DEDUCT of -24 references the order',
            'op' => 'probe.movements_of', 'args' => ['item' => '$r3'],
            'assert' => ['contains' => ['type' => 'ORDER_DEDUCT', 'quantity' => '-24', 'reference' => '$order.order_number']],
        ];

        $run = app(ScenarioRunner::class)->run($this->scenario($plan));

        $this->assertSame(BddRunStatus::Pass, $run->status, json_encode($run->step_results ?? []) ?: '');
    }

    public function test_an_assertion_path_accepts_array_index_brackets(): void
    {
        // The compiler emits `[0]` bracket indexing for "the first movement";
        // dig() must resolve it the same as the dot form `0` (was returning null,
        // so a real ORDER_DEDUCT row read as missing and the step failed).
        $this->grantCreateOrder();
        $plan = $this->ord001Plan();
        $plan['steps'][4] = [
            'keyword' => 'then', 'text' => 'the first movement is the ORDER_DEDUCT',
            'op' => 'probe.movements_of', 'args' => ['item' => '$r3'],
            'assert' => ['path' => '[0]', 'contains' => [
                'type' => 'ORDER_DEDUCT', 'quantity' => '-24', 'reference' => '$order.order_number',
            ]],
        ];

        $run = app(ScenarioRunner::class)->run($this->scenario($plan));

        $this->assertSame(BddRunStatus::Pass, $run->status, json_encode($run->step_results ?? []) ?: '');
    }

    public function test_an_entity_param_tolerates_a_dot_id_reference(): void
    {
        // The model passed "$customer.id" (reaching for a foreign key) where the
        // action wants the Customer entity. Both forms must bind to the capture,
        // not throw "must be a \$ref to a captured Customer".
        $this->grantCreateOrder();
        $plan = $this->ord001Plan();
        $plan['steps'][2]['args']['customer'] = '$customer.id';

        $run = app(ScenarioRunner::class)->run($this->scenario($plan));

        $this->assertSame(BddRunStatus::Pass, $run->status, json_encode($run->step_results ?? []) ?: '');
    }

    public function test_an_overdraw_scenario_passes_via_expect_error(): void
    {
        $this->grantCreateOrder();
        $scenario = $this->scenario(['version' => 1, 'steps' => [
            ['keyword' => 'given', 'text' => '10 bottles in stock',
                'op' => 'seed.inventory_item', 'args' => ['current_stock' => '10'], 'capture' => 'r3'],
            ['keyword' => 'given', 'text' => 'a customer', 'op' => 'seed.customer', 'args' => [], 'capture' => 'customer'],
            ['keyword' => 'when', 'text' => 'ordering 24 bottles is rejected',
                'op' => OperationRegistry::ACTION_PREFIX.CreateOrderAction::class,
                'args' => ['customer' => '$customer', 'data' => [
                    'items' => [['inventory_item_id' => '$r3.id', 'quantity' => 24, 'unit_type' => 'bottles']],
                ]],
                'expect_error' => ['class' => 'InsufficientStockException', 'message_contains' => 'Not enough stock']],
            ['keyword' => 'then', 'text' => 'stock untouched',
                'op' => 'probe.stock_of', 'args' => ['item' => '$r3'], 'assert' => ['equals' => 10]],
        ]]);

        $run = app(ScenarioRunner::class)->run($scenario);

        $this->assertSame(BddRunStatus::Pass, $run->status, json_encode($run->step_results ?? []) ?: '');
    }

    public function test_expect_error_matches_on_message_when_the_model_guessed_the_wrong_class(): void
    {
        // The real failure: the guard threw the right InsufficientStockException,
        // but the model guessed a bogus class and a lower-cased message. The
        // message substring (case-insensitive) is the semantic anchor, so the
        // step must still pass — the right error DID happen.
        $this->grantCreateOrder();
        $scenario = $this->scenario(['version' => 1, 'steps' => [
            ['keyword' => 'given', 'text' => '10 bottles in stock',
                'op' => 'seed.inventory_item', 'args' => ['name' => 'R3 2025', 'current_stock' => '10'], 'capture' => 'r3'],
            ['keyword' => 'given', 'text' => 'a customer', 'op' => 'seed.customer', 'args' => [], 'capture' => 'customer'],
            ['keyword' => 'when', 'text' => 'ordering 24 bottles is rejected',
                'op' => OperationRegistry::ACTION_PREFIX.CreateOrderAction::class,
                'args' => ['customer' => '$customer', 'data' => [
                    'items' => [['inventory_item_id' => '$r3.id', 'quantity' => 24, 'unit_type' => 'bottles']],
                ]],
                'expect_error' => [
                    'class' => 'Spatie\\LaravelData\\Exceptions\\RequiredArgumentMissing',
                    'message_contains' => 'not enough stock',
                ]],
        ]]);

        $run = app(ScenarioRunner::class)->run($scenario);

        $this->assertSame(BddRunStatus::Pass, $run->status, json_encode($run->step_results ?? []) ?: '');
    }

    public function test_expect_error_still_fails_on_a_genuinely_unrelated_error(): void
    {
        // Tolerance must not become "any error passes": an unrelated message and
        // class still fail, so the assertion keeps its teeth.
        $this->grantCreateOrder();
        $scenario = $this->scenario(['version' => 1, 'steps' => [
            ['keyword' => 'given', 'text' => '10 bottles in stock',
                'op' => 'seed.inventory_item', 'args' => ['current_stock' => '10'], 'capture' => 'r3'],
            ['keyword' => 'given', 'text' => 'a customer', 'op' => 'seed.customer', 'args' => [], 'capture' => 'customer'],
            ['keyword' => 'when', 'text' => 'ordering 24 bottles is rejected',
                'op' => OperationRegistry::ACTION_PREFIX.CreateOrderAction::class,
                'args' => ['customer' => '$customer', 'data' => [
                    'items' => [['inventory_item_id' => '$r3.id', 'quantity' => 24, 'unit_type' => 'bottles']],
                ]],
                'expect_error' => ['class' => 'ValidationException', 'message_contains' => 'invalid email address']],
        ]]);

        $run = app(ScenarioRunner::class)->run($scenario);

        $this->assertSame(BddRunStatus::Fail, $run->status);
    }

    public function test_a_failing_assertion_reports_fail_with_step_detail(): void
    {
        $this->grantCreateOrder();
        $plan = $this->ord001Plan();
        $plan['steps'][3]['assert'] = ['equals' => 99]; // wrong expectation

        $run = app(ScenarioRunner::class)->run($this->scenario($plan));

        $this->assertSame(BddRunStatus::Fail, $run->status);
        $failing = collect($run->step_results)->firstWhere('status', 'fail');
        $this->assertNotNull($failing);
        $this->assertStringContainsString('99', (string) $failing['detail']);
    }

    public function test_an_ungranted_operation_parks_the_run_as_needs_access(): void
    {
        // No grant for CreateOrderAction.
        $run = app(ScenarioRunner::class)->run($this->scenario($this->ord001Plan()));

        $this->assertSame(BddRunStatus::NeedsAccess, $run->status);
        $this->assertStringContainsString('CreateOrderAction', (string) $run->error);
    }
}
