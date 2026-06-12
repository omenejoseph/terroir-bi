<?php

declare(strict_types=1);

namespace Tests\Feature\Bdd;

use App\Actions\Billing\DeletePlanAction;
use App\Actions\Orders\CreateOrderAction;
use App\Models\BddOperationGrant;
use App\Services\Bdd\OperationRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OperationRegistryTest extends TestCase
{
    use RefreshDatabase;

    private OperationRegistry $registry;

    protected function setUp(): void
    {
        parent::setUp();
        $this->registry = app(OperationRegistry::class);
    }

    public function test_builtin_seeds_and_probes_are_always_granted(): void
    {
        $this->assertTrue($this->registry->isGranted('seed.inventory_item'));
        $this->assertTrue($this->registry->isGranted('probe.stock_of'));
    }

    public function test_actions_are_fail_closed_until_granted(): void
    {
        $key = OperationRegistry::ACTION_PREFIX.CreateOrderAction::class;

        $this->assertFalse($this->registry->isGranted($key));

        BddOperationGrant::create(['operation_key' => $key]);

        $this->assertTrue($this->registry->isGranted($key));
        $keys = array_map(fn ($spec) => $spec->key, $this->registry->granted());
        $this->assertContains($key, $keys);
    }

    public function test_blocklisted_namespaces_can_never_be_granted_even_with_a_rogue_row(): void
    {
        $key = OperationRegistry::ACTION_PREFIX.DeletePlanAction::class;

        $this->assertTrue($this->registry->isBlocked($key));

        // Even a row inserted behind the UI's back is ignored (defense in depth).
        BddOperationGrant::create(['operation_key' => $key]);
        $this->assertFalse($this->registry->isGranted($key));
        $keys = array_map(fn ($spec) => $spec->key, $this->registry->granted());
        $this->assertNotContains($key, $keys);

        $this->expectException(\RuntimeException::class);
        $this->registry->assertGrantable($key);
    }

    public function test_discovery_excludes_blocklisted_namespaces(): void
    {
        $keys = array_map(fn ($spec) => $spec->key, $this->registry->discoverActions());

        $this->assertContains(OperationRegistry::ACTION_PREFIX.CreateOrderAction::class, $keys);

        foreach ($keys as $key) {
            $this->assertFalse($this->registry->isBlocked($key), "{$key} should not be discoverable.");
        }
        $this->assertNotContains(OperationRegistry::ACTION_PREFIX.DeletePlanAction::class, $keys);
    }

    public function test_describe_action_reflects_the_execute_signature(): void
    {
        $spec = $this->registry->describeAction(CreateOrderAction::class);

        $this->assertNotNull($spec);
        $this->assertSame('action', $spec->kind);
        // Customer is a model param → must be a $ref; createdById auto-fills.
        $this->assertStringContainsString('$ref', $spec->parameters['customer']);
        $this->assertStringContainsString('auto-filled', $spec->parameters['createdById']);
        $this->assertArrayHasKey('data', $spec->parameters);
        $this->assertStringContainsString('Create an internal order', $spec->summary);
    }
}
