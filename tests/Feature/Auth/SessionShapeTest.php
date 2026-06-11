<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Enums\Module;
use App\Enums\TenantRole;
use App\Models\Plan;
use App\Models\TenantSubscription;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\InteractsWithTenancy;
use Tests\TestCase;

class SessionShapeTest extends TestCase
{
    use InteractsWithTenancy;
    use RefreshDatabase;

    public function test_me_exposes_plan_modules_and_full_access(): void
    {
        $plan = Plan::create([
            'name' => 'Basic', 'slug' => 'basic-s', 'currency' => 'EUR',
            'modules' => [Module::Dashboard->value, Module::Inventory->value, Module::Orders->value],
        ]);
        $tenant = $this->createTenant(['plan_id' => $plan->getKey()]);
        Sanctum::actingAs($this->createMember($tenant, [TenantRole::Admin]));

        $this->getJson('/api/v1/auth/me', $this->tenantHeader($tenant))
            ->assertOk()
            ->assertJsonPath('data.access.level', 'full')
            ->assertJsonPath('data.modules', ['dashboard', 'inventory', 'orders']);
    }

    public function test_me_reflects_blocked_access(): void
    {
        $plan = Plan::create([
            'name' => 'Paid', 'slug' => 'paid-s', 'currency' => 'EUR',
            'modules' => Module::values(), 'stripe_price_id' => 'price_x',
            'grace_full_days' => 7, 'grace_readonly_days' => 7,
        ]);
        $tenant = $this->createTenant(['plan_id' => $plan->getKey()]);
        TenantSubscription::create([
            'tenant_id' => $tenant->getKey(),
            'stripe_status' => 'active',
            'current_period_end' => Carbon::now()->subDays(30),
        ]);
        Sanctum::actingAs($this->createMember($tenant, [TenantRole::Admin]));

        $this->getJson('/api/v1/auth/me', $this->tenantHeader($tenant))
            ->assertOk()
            ->assertJsonPath('data.access.level', 'blocked');
    }

    public function test_tenant_without_a_plan_sees_all_modules(): void
    {
        $tenant = $this->createTenant();
        Sanctum::actingAs($this->createMember($tenant, [TenantRole::Admin]));

        $this->getJson('/api/v1/auth/me', $this->tenantHeader($tenant))
            ->assertOk()
            ->assertJsonPath('data.modules', Module::values())
            ->assertJsonPath('data.access.level', 'full');
    }
}
