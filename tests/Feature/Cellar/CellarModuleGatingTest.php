<?php

declare(strict_types=1);

namespace Tests\Feature\Cellar;

use App\Enums\TenantRole;
use App\Models\Plan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\InteractsWithTenancy;
use Tests\TestCase;

class CellarModuleGatingTest extends TestCase
{
    use InteractsWithTenancy;
    use RefreshDatabase;

    /**
     * @param  list<string>  $modules
     */
    private function freePlan(array $modules): Plan
    {
        return Plan::create([
            'name' => 'Plan '.fake()->unique()->word(),
            'slug' => fake()->unique()->slug(2),
            'modules' => $modules,
            // No stripe_price_id ⇒ free plan ⇒ Full subscription access.
        ]);
    }

    public function test_plan_without_cellar_is_blocked_with_module_code(): void
    {
        $plan = $this->freePlan(['orders']);
        $tenant = $this->createTenant(['plan_id' => $plan->getKey()]);
        $admin = $this->createMember($tenant, [TenantRole::Admin]);

        Sanctum::actingAs($admin);

        $this->getJson('/api/v1/vessels', $this->tenantHeader($tenant))
            ->assertForbidden()
            ->assertJsonPath('code', 'module_not_in_plan');
    }

    public function test_plan_with_cellar_passes(): void
    {
        $plan = $this->freePlan(['cellar']);
        $tenant = $this->createTenant(['plan_id' => $plan->getKey()]);
        $admin = $this->createMember($tenant, [TenantRole::Admin]);

        Sanctum::actingAs($admin);

        $this->getJson('/api/v1/vessels', $this->tenantHeader($tenant))
            ->assertOk()
            ->assertJsonPath('data', []);
    }

    public function test_tenant_without_a_plan_is_unrestricted(): void
    {
        $tenant = $this->createTenant();
        $admin = $this->createMember($tenant, [TenantRole::Admin]);

        Sanctum::actingAs($admin);

        $this->getJson('/api/v1/vessels', $this->tenantHeader($tenant))->assertOk();
    }

    public function test_member_without_cellar_capability_is_forbidden(): void
    {
        $tenant = $this->createTenant();
        $sales = $this->createMember($tenant, [TenantRole::Sales]);

        Sanctum::actingAs($sales);

        // Module is unrestricted (no plan) but the Sales role lacks cellar.view.
        $this->getJson('/api/v1/vessels', $this->tenantHeader($tenant))->assertForbidden();
    }
}
