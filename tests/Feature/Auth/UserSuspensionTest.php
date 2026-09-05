<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Actions\Tenancy\SetPlatformAdminAction;
use App\Actions\Users\SetUserSuspendedAction;
use App\Enums\TenantRole;
use App\Models\User;
use App\Services\Auth\ActiveTenantSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithTenancy;
use Tests\TestCase;

/**
 * Suspension is a platform-admin-only account flag, independent of any
 * tenant membership — see App\Actions\Users\SetUserSuspendedAction and
 * App\Http\Middleware\EnsureUserNotSuspended.
 */
class UserSuspensionTest extends TestCase
{
    use InteractsWithTenancy;
    use RefreshDatabase;

    private function admin(): User
    {
        $admin = $this->createUser();

        return app(SetPlatformAdminAction::class)->execute($admin, true);
    }

    public function test_a_suspended_user_cannot_log_in(): void
    {
        $tenant = $this->createTenant();
        $user = $this->createMember($tenant, [TenantRole::Admin]);
        app(SetUserSuspendedAction::class)->execute($user, true, $this->admin());

        $this->post('/login', ['email' => $user->email, 'password' => 'password'])
            ->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_suspending_a_user_mid_session_logs_them_out_on_their_next_request(): void
    {
        $tenant = $this->createTenant();
        $user = $this->createMember($tenant, [TenantRole::Admin]);

        $session = [ActiveTenantSession::KEY => $tenant->getKey()];
        $this->actingAs($user)->withSession($session)->get('/dashboard')->assertSuccessful();

        app(SetUserSuspendedAction::class)->execute($user, true, $this->admin());

        // The session cookie is still the same; the very next request must
        // still be rejected — enforcement is per-request, not just at login.
        $this->withSession($session)->get('/dashboard')->assertRedirect('/login');
        $this->assertGuest();
    }

    public function test_suspending_a_user_revokes_their_api_tokens(): void
    {
        $tenant = $this->createTenant();
        $user = $this->createMember($tenant, [TenantRole::Admin]);

        $token = $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'password',
        ])->assertOk()->json('data.token');

        $this->withToken($token)->getJson('/api/v1/auth/me')->assertOk();

        app(SetUserSuspendedAction::class)->execute($user, true, $this->admin());
        $this->assertSame(0, $user->tokens()->toBase()->count());

        // The auth guard caches the resolved user within a test process
        // (see TenantSwitchTest's own use of this); without forgetting it,
        // the second call would just reuse the first request's resolved user
        // instead of re-validating the (now-deleted) token.
        $this->app['auth']->forgetGuards();

        // The same, now-deleted token is rejected outright by Sanctum's own
        // guard — this is what actually cuts off token-based access, not a
        // per-request suspended-user check on this (non-web) surface.
        $this->withToken($token)->getJson('/api/v1/auth/me')->assertUnauthorized();
    }

    public function test_unsuspending_restores_login(): void
    {
        $tenant = $this->createTenant();
        $user = $this->createMember($tenant, [TenantRole::Admin]);
        $admin = $this->admin();
        app(SetUserSuspendedAction::class)->execute($user, true, $admin);
        app(SetUserSuspendedAction::class)->execute($user, false, $admin);

        $this->post('/login', ['email' => $user->email, 'password' => 'password'])
            ->assertRedirect('/dashboard');
    }

    public function test_a_platform_admin_cannot_suspend_themselves(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)
            ->patch("/admin/users/{$admin->getKey()}/suspension", ['suspended' => true])
            ->assertSessionHasErrors('suspended');

        $this->assertFalse($admin->fresh()?->isSuspended());
    }

    public function test_a_platform_admin_can_suspend_and_unsuspend_another_user(): void
    {
        $tenant = $this->createTenant();
        $user = $this->createMember($tenant, [TenantRole::Admin]);

        $this->actingAs($this->admin())
            ->patch("/admin/users/{$user->getKey()}/suspension", ['suspended' => true])
            ->assertRedirect();
        $this->assertTrue($user->fresh()?->isSuspended());

        $this->actingAs($this->admin())
            ->patch("/admin/users/{$user->getKey()}/suspension", ['suspended' => false])
            ->assertRedirect();
        $this->assertFalse($user->fresh()->isSuspended());
    }

    public function test_suspension_is_closed_to_a_non_platform_admin(): void
    {
        $tenant = $this->createTenant();
        $user = $this->createMember($tenant, [TenantRole::Admin]);

        $this->actingAs($user)
            ->patch("/admin/users/{$user->getKey()}/suspension", ['suspended' => true])
            ->assertForbidden();
    }
}
