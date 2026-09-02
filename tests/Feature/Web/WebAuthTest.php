<?php

declare(strict_types=1);

namespace Tests\Feature\Web;

use App\Enums\MembershipStatus;
use App\Enums\TenantRole;
use App\Services\Auth\ActiveTenantSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Inertia\Testing\AssertableInertia;
use Tests\Concerns\InteractsWithTenancy;
use Tests\TestCase;

/**
 * Session (cookie) authentication for the Inertia frontend. The token flow in
 * tests/Feature/Auth covers the API side; these assert the web side reaches the
 * same decisions through the shared UserAuthenticator/TenantSwitcher services.
 */
class WebAuthTest extends TestCase
{
    use InteractsWithTenancy;
    use RefreshDatabase;

    public function test_login_page_renders(): void
    {
        $this->get('/login')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('Auth/Login'));
    }

    public function test_login_authenticates_and_selects_the_first_active_tenant(): void
    {
        $tenant = $this->createTenant();
        $user = $this->createMember($tenant, [TenantRole::Admin], [
            'password' => Hash::make('secret-password'),
        ]);

        $this->post('/login', ['email' => $user->email, 'password' => 'secret-password'])
            ->assertRedirect('/dashboard');

        $this->assertAuthenticatedAs($user);
        $this->assertSame($tenant->getKey(), session(ActiveTenantSession::KEY));
    }

    public function test_login_rejects_bad_credentials(): void
    {
        $tenant = $this->createTenant();
        $user = $this->createMember($tenant, [TenantRole::Admin], [
            'password' => Hash::make('secret-password'),
        ]);

        $this->from('/login')
            ->post('/login', ['email' => $user->email, 'password' => 'wrong-password'])
            ->assertRedirect('/login')
            ->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_login_honours_an_explicit_tenant_the_user_belongs_to(): void
    {
        $first = $this->createTenant();
        $second = $this->createTenant();
        $user = $this->createMember($first, [TenantRole::Admin], [
            'password' => Hash::make('secret-password'),
        ]);
        $this->createMembershipFor($user, $second, [TenantRole::Admin]);

        $this->post('/login', [
            'email' => $user->email,
            'password' => 'secret-password',
            'tenant_id' => $second->getKey(),
        ])->assertRedirect('/dashboard');

        $this->assertSame($second->getKey(), session(ActiveTenantSession::KEY));
    }

    public function test_login_refuses_a_tenant_the_user_is_not_an_active_member_of(): void
    {
        $tenant = $this->createTenant();
        $other = $this->createTenant();
        $user = $this->createMember($tenant, [TenantRole::Admin], [
            'password' => Hash::make('secret-password'),
        ]);

        $this->from('/login')->post('/login', [
            'email' => $user->email,
            'password' => 'secret-password',
            'tenant_id' => $other->getKey(),
        ])->assertSessionHasErrors('tenant_id');

        $this->assertGuest();
    }

    public function test_logout_clears_the_session_and_active_tenant(): void
    {
        $tenant = $this->createTenant();
        $user = $this->createMember($tenant, [TenantRole::Admin]);

        $this->actingAs($user)
            ->withSession([ActiveTenantSession::KEY => $tenant->getKey()])
            ->post('/logout')
            ->assertRedirect('/login');

        $this->assertGuest();
    }

    public function test_guests_are_redirected_to_login(): void
    {
        $this->get('/dashboard')->assertRedirect('/login');
    }

    public function test_tenant_switch_rebinds_the_session_tenant(): void
    {
        $first = $this->createTenant();
        $second = $this->createTenant();
        $user = $this->createMember($first, [TenantRole::Admin]);
        $this->createMembershipFor($user, $second, [TenantRole::Admin]);

        $this->actingAs($user)
            ->withSession([ActiveTenantSession::KEY => $first->getKey()])
            ->from('/dashboard')
            ->post('/tenant/switch', ['tenant_id' => $second->getKey()])
            ->assertRedirect('/dashboard');

        $this->assertSame($second->getKey(), session(ActiveTenantSession::KEY));
    }

    public function test_tenant_switch_is_refused_for_a_non_member(): void
    {
        $tenant = $this->createTenant();
        $foreign = $this->createTenant();
        $user = $this->createMember($tenant, [TenantRole::Admin]);

        $this->actingAs($user)
            ->withSession([ActiveTenantSession::KEY => $tenant->getKey()])
            ->post('/tenant/switch', ['tenant_id' => $foreign->getKey()])
            ->assertForbidden();

        $this->assertSame($tenant->getKey(), session(ActiveTenantSession::KEY));
    }

    public function test_tenant_switch_is_refused_when_the_membership_is_not_active(): void
    {
        $tenant = $this->createTenant();
        $suspended = $this->createTenant();
        $user = $this->createMember($tenant, [TenantRole::Admin]);
        $this->createMembershipFor($user, $suspended, [TenantRole::Admin], MembershipStatus::Suspended);

        $this->actingAs($user)
            ->withSession([ActiveTenantSession::KEY => $tenant->getKey()])
            ->post('/tenant/switch', ['tenant_id' => $suspended->getKey()])
            ->assertForbidden();
    }
}
