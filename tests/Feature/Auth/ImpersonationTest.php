<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Actions\Tenancy\SetPlatformAdminAction;
use App\Actions\Users\SetUserSuspendedAction;
use App\Enums\TenantRole;
use App\Models\User;
use App\Services\Auth\ActiveTenantSession;
use App\Services\Auth\ImpersonationSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithTenancy;
use Tests\TestCase;

class ImpersonationTest extends TestCase
{
    use InteractsWithTenancy;
    use RefreshDatabase;

    private function admin(): User
    {
        $admin = $this->createUser();

        return app(SetPlatformAdminAction::class)->execute($admin, true);
    }

    public function test_a_platform_admin_can_impersonate_a_tenant_member(): void
    {
        $admin = $this->admin();
        $tenant = $this->createTenant();
        $user = $this->createMember($tenant, [TenantRole::Admin]);

        $this->actingAs($admin)
            ->post("/admin/users/{$user->getKey()}/impersonate")
            ->assertRedirect('/dashboard');

        $this->assertAuthenticatedAs($user);
        $this->assertSame($admin->getKey(), session(ImpersonationSession::KEY));

        // The impersonated session actually works — lands in the target's tenant.
        $this->get('/dashboard')->assertSuccessful();
    }

    public function test_stopping_restores_the_admins_own_session(): void
    {
        $admin = $this->admin();
        $tenant = $this->createTenant();
        $user = $this->createMember($tenant, [TenantRole::Admin]);

        $this->actingAs($admin)->post("/admin/users/{$user->getKey()}/impersonate")->assertRedirect();
        $this->assertAuthenticatedAs($user);

        // Stopping is reachable even though the CURRENT user (the target) is
        // an ordinary tenant member, not a platform admin — see
        // StopImpersonationController's docblock for why this route sits
        // outside platform.admin/tenant.web.
        $this->post('/impersonation/stop')->assertRedirect('/admin');

        $this->assertAuthenticatedAs($admin);
        $this->assertNull(session(ImpersonationSession::KEY));
    }

    /**
     * Regression: a platform admin who ALSO holds a real tenant membership
     * must be able to navigate back into the tenant app after stopping —
     * StopImpersonationAction used to unconditionally forget the active
     * tenant, leaving ResolveTenant with nothing to resolve on the very next
     * tenant.web visit ("No active tenant for this request.").
     */
    public function test_stopping_lets_an_admin_with_a_membership_return_to_their_own_tenant(): void
    {
        $admin = $this->admin();
        $ownTenant = $this->createTenant();
        $this->createMembershipFor($admin, $ownTenant, [TenantRole::Admin]);

        $otherTenant = $this->createTenant();
        $target = $this->createMember($otherTenant, [TenantRole::Admin]);

        $this->actingAs($admin)->post("/admin/users/{$target->getKey()}/impersonate")->assertRedirect();
        $this->post('/impersonation/stop')->assertRedirect('/admin');

        $this->assertAuthenticatedAs($admin);
        $this->assertSame($ownTenant->getKey(), session(ActiveTenantSession::KEY));

        // The actual bug report: this 400ed with "No active tenant for this
        // request." before the fix.
        $this->get('/dashboard')->assertSuccessful();
    }

    public function test_cannot_impersonate_yourself(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)
            ->post("/admin/users/{$admin->getKey()}/impersonate")
            ->assertSessionHasErrors('user');

        $this->assertAuthenticatedAs($admin);
    }

    public function test_cannot_impersonate_another_platform_admin(): void
    {
        $admin = $this->admin();
        $otherAdmin = $this->admin();

        $this->actingAs($admin)
            ->post("/admin/users/{$otherAdmin->getKey()}/impersonate")
            ->assertSessionHasErrors('user');

        $this->assertAuthenticatedAs($admin);
    }

    public function test_cannot_impersonate_a_suspended_user(): void
    {
        $admin = $this->admin();
        $tenant = $this->createTenant();
        $user = $this->createMember($tenant, [TenantRole::Admin]);
        app(SetUserSuspendedAction::class)->execute($user, true, $admin);

        $this->actingAs($admin)
            ->post("/admin/users/{$user->getKey()}/impersonate")
            ->assertSessionHasErrors('user');

        $this->assertAuthenticatedAs($admin);
    }

    public function test_cannot_start_a_nested_impersonation(): void
    {
        $admin = $this->admin();
        $tenant = $this->createTenant();
        $first = $this->createMember($tenant, [TenantRole::Admin]);
        $second = $this->createMember($tenant, [TenantRole::Team]);

        $this->actingAs($admin)->post("/admin/users/{$first->getKey()}/impersonate")->assertRedirect();
        $this->assertAuthenticatedAs($first);

        // Now impersonating $first; the platform.admin route is closed to
        // them anyway (they aren't a platform admin), so this also exercises
        // that a non-admin session can't reach the start endpoint at all.
        $this->post("/admin/users/{$second->getKey()}/impersonate")->assertForbidden();
    }

    public function test_impersonation_is_closed_to_a_non_platform_admin(): void
    {
        $tenant = $this->createTenant();
        $actor = $this->createMember($tenant, [TenantRole::Admin]);
        $target = $this->createMember($tenant, [TenantRole::Team]);

        $this->actingAs($actor)
            ->post("/admin/users/{$target->getKey()}/impersonate")
            ->assertForbidden();
    }

    public function test_stopping_with_no_active_impersonation_404s(): void
    {
        $tenant = $this->createTenant();
        $user = $this->createMember($tenant, [TenantRole::Admin]);

        $this->actingAs($user)->post('/impersonation/stop')->assertNotFound();
    }
}
