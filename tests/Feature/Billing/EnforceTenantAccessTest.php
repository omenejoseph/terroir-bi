<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Enums\Module;
use App\Enums\TenantRole;
use App\Models\Plan;
use App\Models\Tenant;
use App\Models\TenantSubscription;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\InteractsWithTenancy;
use Tests\TestCase;

class EnforceTenantAccessTest extends TestCase
{
    use InteractsWithTenancy;
    use RefreshDatabase;

    /** A paid plan (has a Stripe price) with every module, so only billing access gates. */
    private function paidPlan(): Plan
    {
        return Plan::create([
            'name' => 'Paid', 'slug' => 'paid-x', 'currency' => 'EUR',
            'modules' => Module::values(), 'stripe_price_id' => 'price_x',
            'grace_full_days' => 7, 'grace_readonly_days' => 7,
        ]);
    }

    /** Tenant on the paid plan with a subscription whose period ended `$endedDaysAgo` ago. */
    private function tenantEndedDaysAgo(int $endedDaysAgo): Tenant
    {
        $tenant = $this->createTenant(['plan_id' => $this->paidPlan()->getKey()]);
        TenantSubscription::create([
            'tenant_id' => $tenant->getKey(),
            'stripe_status' => 'active',
            'current_period_end' => Carbon::now()->subDays($endedDaysAgo),
        ]);
        Sanctum::actingAs($this->createMember($tenant, [TenantRole::Admin]));

        return $tenant;
    }

    public function test_full_access_allows_writes(): void
    {
        $tenant = $this->createTenant(['plan_id' => $this->paidPlan()->getKey()]);
        TenantSubscription::create([
            'tenant_id' => $tenant->getKey(),
            'stripe_status' => 'active',
            'current_period_end' => Carbon::now()->addDays(10),
        ]);
        Sanctum::actingAs($this->createMember($tenant, [TenantRole::Admin]));

        $this->postJson('/api/v1/work-orders', ['title' => 'Rack barrels'], $this->tenantHeader($tenant))
            ->assertCreated();
    }

    public function test_read_only_grace_allows_reads_blocks_writes(): void
    {
        $tenant = $this->tenantEndedDaysAgo(10); // past full grace (7), inside read-only (14)

        $this->getJson('/api/v1/work-orders', $this->tenantHeader($tenant))->assertOk();

        $this->postJson('/api/v1/work-orders', ['title' => 'Nope'], $this->tenantHeader($tenant))
            ->assertStatus(403)
            ->assertJsonPath('code', 'subscription_read_only');
    }

    public function test_blocked_forbids_everything_but_allows_dashboard(): void
    {
        $tenant = $this->tenantEndedDaysAgo(30); // past all grace

        $this->getJson('/api/v1/work-orders', $this->tenantHeader($tenant))
            ->assertStatus(403)
            ->assertJsonPath('code', 'subscription_blocked');

        // Dashboard stays reachable so the FE can render the blocked screen shell.
        $this->getJson('/api/v1/dashboard', $this->tenantHeader($tenant))->assertOk();
    }
}
