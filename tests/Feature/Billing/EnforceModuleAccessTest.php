<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Enums\Module;
use App\Enums\TenantRole;
use App\Models\Plan;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\InteractsWithTenancy;
use Tests\TestCase;

class EnforceModuleAccessTest extends TestCase
{
    use InteractsWithTenancy;
    use RefreshDatabase;

    /**
     * A free plan (no Stripe price) so only module gating applies, not billing access.
     *
     * @param  list<string>  $modules
     */
    private function plan(string $slug, array $modules): Plan
    {
        return Plan::create([
            'name' => $slug, 'slug' => $slug, 'currency' => 'EUR', 'modules' => $modules,
        ]);
    }

    /** @return array{Tenant, User} */
    private function actingTenant(Plan $plan): array
    {
        $tenant = $this->createTenant(['plan_id' => $plan->getKey()]);
        $admin = $this->createMember($tenant, [TenantRole::Admin]);
        Sanctum::actingAs($admin);

        return [$tenant, $admin];
    }

    public function test_module_not_in_plan_is_forbidden(): void
    {
        [$tenant] = $this->actingTenant($this->plan('basic-x', [
            Module::Dashboard->value, Module::Inventory->value, Module::Orders->value,
        ]));

        // costs is NOT in the plan.
        $this->getJson('/api/v1/costs', $this->tenantHeader($tenant))
            ->assertStatus(403)
            ->assertJsonPath('code', 'module_not_in_plan');

        // inventory IS in the plan.
        $this->getJson('/api/v1/inventory-items', $this->tenantHeader($tenant))->assertOk();
    }

    public function test_full_plan_reaches_every_module(): void
    {
        [$tenant] = $this->actingTenant($this->plan('estate-x', Module::values()));

        $this->getJson('/api/v1/costs', $this->tenantHeader($tenant))->assertOk();
        $this->getJson('/api/v1/cash-flow', $this->tenantHeader($tenant))->assertOk();
        $this->getJson('/api/v1/inflows', $this->tenantHeader($tenant))->assertOk();
    }

    public function test_tenant_without_a_plan_is_unrestricted(): void
    {
        $tenant = $this->createTenant(); // no plan_id
        Sanctum::actingAs($this->createMember($tenant, [TenantRole::Admin]));

        $this->getJson('/api/v1/costs', $this->tenantHeader($tenant))->assertOk();
    }
}
