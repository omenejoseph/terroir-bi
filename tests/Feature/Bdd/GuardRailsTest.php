<?php

declare(strict_types=1);

namespace Tests\Feature\Bdd;

use App\Actions\Bdd\GrantBddOperationAction;
use App\Actions\Orders\CreateOrderAction;
use App\Enums\AiCapability;
use App\Enums\BddRunStatus;
use App\Enums\BddScenarioStatus;
use App\Enums\TenantRole;
use App\Models\BddOperationGrant;
use App\Models\BddScenario;
use App\Models\BddScenarioRun;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Tenant;
use App\Services\Ai\AiClient;
use App\Services\Bdd\LiveExecutionContext;
use App\Services\Bdd\LiveScenarioRunner;
use App\Services\Bdd\OperationRegistry;
use App\Services\Bdd\SandboxFactory;
use App\Services\Bdd\Tools\FinishTool;
use App\Tenancy\Contracts\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Tools\Request;
use RuntimeException;
use Tests\Concerns\InteractsWithTenancy;
use Tests\Support\Bdd\FakeBddAiClient;
use Tests\TestCase;

class GuardRailsTest extends TestCase
{
    use InteractsWithTenancy;
    use RefreshDatabase;

    private function scenario(): BddScenario
    {
        return BddScenario::create([
            'title' => 'Guard rail probe', 'slug' => 'guard-'.uniqid(),
            'gherkin' => 'Scenario: guard rails hold', 'status' => BddScenarioStatus::Ready,
        ]);
    }

    /**
     * @param  list<array{0: string, 1: array<string, mixed>}>  $script
     * @return array{0: BddScenarioRun, 1: FakeBddAiClient}
     */
    private function runScripted(array $script): array
    {
        $ai = new FakeBddAiClient($script);
        $this->app->instance(AiClient::class, $ai);

        return [app(LiveScenarioRunner::class)->run($this->scenario()), $ai];
    }

    public function test_a_step_cannot_smuggle_a_raw_id_to_reach_outside_the_sandbox(): void
    {
        // A REAL tenant with a REAL customer exists in the database…
        $realTenant = $this->createTenant();
        $this->createMember($realTenant, [TenantRole::Admin]);
        $this->actingAsTenant($realTenant);
        $realCustomer = Customer::create(['company_name' => 'Real Buyer', 'email' => 'real@example.com']);
        $this->forgetTenant();

        BddOperationGrant::create(['operation_key' => OperationRegistry::ACTION_PREFIX.CreateOrderAction::class]);

        // …and the "model" tries to pass its raw id as an argument.
        [$run, $ai] = $this->runScripted([
            ['given', ['text' => 'item', 'op' => 'seed.inventory_item', 'args_json' => '{}', 'capture' => 'item']],
            ['when', ['text' => 'order for the real customer',
                'op' => OperationRegistry::ACTION_PREFIX.CreateOrderAction::class,
                'args_json' => json_encode(['customer' => $realCustomer->getKey(), 'data' => [
                    'items' => [['inventory_item_id' => '$item.id', 'quantity' => 1, 'unit_type' => 'bottles']],
                ]])]],
            ['finish', []],
        ]);

        // The raw id is rejected at interpolation — the order never happens.
        $this->assertNotSame(BddRunStatus::Pass, $run->status);
        $this->assertStringContainsString('Raw entity ids are not allowed', $ai->results[1]);
        $this->assertSame(0, Order::query()->withoutGlobalScopes()->count());
    }

    public function test_undefined_references_are_fed_back_not_executed(): void
    {
        [$run, $ai] = $this->runScripted([
            ['probe', ['op' => 'probe.stock_of', 'args_json' => json_encode(['item' => '$ghost'])]],
            ['finish', []],
        ]);

        $this->assertSame(BddRunStatus::Error, $run->status);
        $this->assertStringContainsString('Unknown reference $ghost', $ai->results[0]);
    }

    public function test_blocklisted_grants_are_refused(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('blocklist');

        app(GrantBddOperationAction::class)->execute('action:App\\Actions\\Billing\\DeletePlanAction');
    }

    public function test_db_count_probe_rejects_non_whitelisted_tables(): void
    {
        [$run, $ai] = $this->runScripted([
            ['probe', ['op' => 'probe.db_count', 'args_json' => json_encode(['table' => 'users'])]],
            ['finish', []],
        ]);

        $this->assertNotSame(BddRunStatus::Pass, $run->status);
        $this->assertStringContainsString('Guard rail', $ai->results[0]);
    }

    public function test_a_mutated_tenant_context_aborts_the_tool_call(): void
    {
        // Something inside the run swapped the tenant context away from the
        // sandbox — the very next tool call must abort the whole run.
        $other = $this->createTenant();

        DB::beginTransaction();

        try {
            $sandbox = app(SandboxFactory::class)->create();
            $context = new LiveExecutionContext($sandbox);

            app(TenantContext::class)->makeCurrent($other);
            $tool = new FinishTool($context, app(TenantContext::class));

            try {
                $tool->handle(new Request([]));
                $this->fail('The tool should have aborted on the mutated tenant context.');
            } catch (RuntimeException $e) {
                $this->assertStringContainsString('tenant context was mutated', $e->getMessage());
            }
        } finally {
            DB::rollBack();
            $this->forgetTenant();
        }
    }

    public function test_a_run_level_tenant_mutation_is_caught_and_reported_as_error(): void
    {
        // The whole loop ran but left the tenant context pointing elsewhere —
        // the runner's post-loop check turns that into an Error verdict.
        $other = $this->createTenant();

        $ai = new class($other) extends FakeBddAiClient
        {
            public function __construct(private readonly Tenant $other)
            {
                parent::__construct([]);
            }

            public function prompt(Agent $agent, string $prompt, AiCapability $capability, string $feature, array $attachments = [], ?string $importId = null, ?int $timeout = null): AgentResponse
            {
                app(TenantContext::class)->makeCurrent($this->other);

                return parent::prompt($agent, $prompt, $capability, $feature, $attachments, $importId, $timeout);
            }
        };
        $this->app->instance(AiClient::class, $ai);

        $run = app(LiveScenarioRunner::class)->run($this->scenario());

        $this->assertSame(BddRunStatus::Error, $run->status);
        $this->assertStringContainsString('mutated the tenant context', (string) $run->error);
    }

    public function test_seeds_and_probes_need_no_grant_but_actions_always_do(): void
    {
        $registry = app(OperationRegistry::class);

        $this->assertTrue($registry->isGranted('seed.customer'));
        $this->assertTrue($registry->isGranted('probe.spend_summary'));
        $this->assertFalse($registry->isGranted(OperationRegistry::ACTION_PREFIX.CreateOrderAction::class));

        // Grant rows for non-action keys are meaningless and rejected.
        $this->expectException(RuntimeException::class);
        app(GrantBddOperationAction::class)->execute('seed.customer');
    }
}
