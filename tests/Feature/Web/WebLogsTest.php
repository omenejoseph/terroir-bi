<?php

declare(strict_types=1);

namespace Tests\Feature\Web;

use App\Enums\Module;
use App\Enums\TenantRole;
use App\Models\AuditLog;
use App\Models\Plan;
use App\Services\Auth\ActiveTenantSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\Concerns\InteractsWithTenancy;
use Tests\TestCase;

/**
 * The Inertia "Logs" page (Web\LogController) — a tenant's own audit trail,
 * scoped strictly to that tenant and, unlike Web\Admin\AuditLogController's
 * platform-wide view, closed to anyone but the tenant's Admin role.
 */
class WebLogsTest extends TestCase
{
    use InteractsWithTenancy;
    use RefreshDatabase;

    public function test_admin_sees_only_this_tenants_own_entries(): void
    {
        $tenant = $this->createTenant();
        $admin = $this->createMember($tenant, [TenantRole::Admin]);
        $other = $this->createTenant();

        AuditLog::create(['tenant_id' => $tenant->getKey(), 'action' => 'order.status_changed']);
        AuditLog::create(['tenant_id' => $other->getKey(), 'action' => 'order.status_changed']);

        // A platform-level row with no tenant at all (e.g. impersonation)
        // must not leak into a tenant's own view either.
        AuditLog::create(['tenant_id' => null, 'action' => 'user.impersonation.started']);

        $this->actingAs($admin)
            ->withSession([ActiveTenantSession::KEY => $tenant->getKey()])
            ->get('/logs')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Logs/Index')
                ->has('logs.data', 1)
                ->where('logs.data.0.action', 'order.status_changed'));
    }

    public function test_the_page_is_closed_to_a_non_admin_role(): void
    {
        $tenant = $this->createTenant();
        $member = $this->createMember($tenant, [TenantRole::Team]);

        $this->actingAs($member)
            ->withSession([ActiveTenantSession::KEY => $tenant->getKey()])
            ->get('/logs')
            ->assertForbidden();
    }

    public function test_a_plan_without_the_audit_log_module_is_forbidden(): void
    {
        $plan = Plan::create([
            'name' => 'basic', 'slug' => 'basic', 'currency' => 'EUR',
            'modules' => [Module::Dashboard->value],
        ]);
        $tenant = $this->createTenant(['plan_id' => $plan->getKey()]);
        $admin = $this->createMember($tenant, [TenantRole::Admin]);

        $this->actingAs($admin)
            ->withSession([ActiveTenantSession::KEY => $tenant->getKey()])
            ->get('/logs')
            ->assertForbidden();
    }

    public function test_a_plan_with_the_audit_log_module_reaches_the_page(): void
    {
        $plan = Plan::create([
            'name' => 'estate', 'slug' => 'estate', 'currency' => 'EUR',
            'modules' => [Module::Dashboard->value, Module::AuditLog->value],
        ]);
        $tenant = $this->createTenant(['plan_id' => $plan->getKey()]);
        $admin = $this->createMember($tenant, [TenantRole::Admin]);

        $this->actingAs($admin)
            ->withSession([ActiveTenantSession::KEY => $tenant->getKey()])
            ->get('/logs')
            ->assertOk();
    }
}
