<?php

declare(strict_types=1);

namespace Tests\Feature\Bdd;

use App\Actions\Bdd\GrantBddOperationAction;
use App\Actions\Orders\CreateOrderAction;
use App\Enums\BddRunStatus;
use App\Enums\BddScenarioStatus;
use App\Enums\TenantRole;
use App\Models\BddOperationGrant;
use App\Models\BddScenario;
use App\Models\Customer;
use App\Models\Order;
use App\Services\Bdd\OperationRegistry;
use App\Services\Bdd\PlanValidator;
use App\Services\Bdd\ScenarioRunner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\Concerns\InteractsWithTenancy;
use Tests\TestCase;

class GuardRailsTest extends TestCase
{
    use InteractsWithTenancy;
    use RefreshDatabase;

    public function test_raw_entity_ids_in_plans_are_rejected_by_the_validator(): void
    {
        $ulid = strtoupper((string) str()->ulid());

        $result = app(PlanValidator::class)->validate(['version' => 1, 'steps' => [
            ['keyword' => 'given', 'text' => 'x', 'op' => 'seed.customer', 'args' => [], 'capture' => 'c'],
            ['keyword' => 'then', 'text' => 'y', 'op' => 'probe.db_count', 'args' => ['table' => 'orders', 'sneaky' => $ulid], 'assert' => ['equals' => 0]],
        ]]);

        $this->assertNotEmpty(array_filter($result['errors'], fn (string $e) => str_contains($e, 'raw entity ids')));
    }

    public function test_undefined_references_are_rejected(): void
    {
        $result = app(PlanValidator::class)->validate(['version' => 1, 'steps' => [
            ['keyword' => 'then', 'text' => 'y', 'op' => 'probe.stock_of', 'args' => ['item' => '$ghost'], 'assert' => ['equals' => 1]],
        ]]);

        $this->assertNotEmpty(array_filter($result['errors'], fn (string $e) => str_contains($e, '$ghost')));
    }

    public function test_blocklisted_grants_are_refused(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('blocklist');

        app(GrantBddOperationAction::class)->execute('action:App\\Actions\\Billing\\DeletePlanAction');
    }

    public function test_db_count_probe_rejects_non_whitelisted_tables(): void
    {
        BddOperationGrant::create(['operation_key' => OperationRegistry::ACTION_PREFIX.CreateOrderAction::class]);

        $scenario = BddScenario::create([
            'title' => 'Sneaky count', 'slug' => 'sneaky-'.uniqid(), 'gherkin' => 'x',
            'status' => BddScenarioStatus::Ready,
            'compiled_plan' => ['version' => 1, 'steps' => [
                ['keyword' => 'then', 'text' => 'count users', 'op' => 'probe.db_count', 'args' => ['table' => 'users'], 'assert' => ['equals' => 0]],
            ]],
        ]);

        $run = app(ScenarioRunner::class)->run($scenario);

        $this->assertSame(BddRunStatus::Fail, $run->status);
        $detail = (string) ($run->step_results[0]['detail'] ?? '');
        $this->assertStringContainsString('Guard rail', $detail);
    }

    public function test_a_step_cannot_reach_a_record_outside_the_sandbox(): void
    {
        // A REAL tenant with a REAL customer exists in the database…
        $realTenant = $this->createTenant();
        $this->createMember($realTenant, [TenantRole::Admin]);
        $this->actingAsTenant($realTenant);
        $realCustomer = Customer::create(['company_name' => 'Real Buyer', 'email' => 'real@example.com']);
        $this->forgetTenant();

        BddOperationGrant::create(['operation_key' => OperationRegistry::ACTION_PREFIX.CreateOrderAction::class]);

        // …and a plan tries to smuggle its id in as an argument.
        $scenario = BddScenario::create([
            'title' => 'Cross-tenant order', 'slug' => 'cross-'.uniqid(), 'gherkin' => 'x',
            'status' => BddScenarioStatus::Ready,
            'compiled_plan' => ['version' => 1, 'steps' => [
                ['keyword' => 'given', 'text' => 'item', 'op' => 'seed.inventory_item', 'args' => [], 'capture' => 'item'],
                ['keyword' => 'when', 'text' => 'order for the real customer',
                    'op' => OperationRegistry::ACTION_PREFIX.CreateOrderAction::class,
                    'args' => ['customer' => $realCustomer->getKey(), 'data' => [
                        'items' => [['inventory_item_id' => '$item.id', 'quantity' => 1, 'unit_type' => 'bottles']],
                    ]]],
            ]],
        ]);

        $run = app(ScenarioRunner::class)->run($scenario);

        // The raw id is caught (validator at run start) — the order never happens.
        $this->assertNotSame(BddRunStatus::Pass, $run->status);
        $this->assertSame(0, Order::query()->withoutGlobalScopes()->count());
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
