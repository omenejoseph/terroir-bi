<?php

declare(strict_types=1);

namespace Tests\Feature\Web;

use App\Enums\MembershipStatus;
use App\Enums\TenantRole;
use App\Services\Auth\ActiveTenantSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithTenancy;
use Tests\TestCase;

/**
 * The 'session' strategy added to ResolveTenant.
 *
 * The session only *nominates* a tenant — membership is still verified on every
 * request, so these assert that a session naming a tenant the user does not
 * belong to is refused rather than honoured.
 */
class WebTenantResolutionTest extends TestCase
{
    use InteractsWithTenancy;
    use RefreshDatabase;

    public function test_active_tenant_comes_from_the_session(): void
    {
        $tenant = $this->createTenant();
        $user = $this->createMember($tenant, [TenantRole::Admin]);

        $this->actingAs($user)
            ->withSession([ActiveTenantSession::KEY => $tenant->getKey()])
            ->get('/dashboard')
            ->assertOk();
    }

    public function test_request_without_an_active_tenant_is_rejected(): void
    {
        $tenant = $this->createTenant();
        $user = $this->createMember($tenant, [TenantRole::Admin]);

        // Authenticated, but nothing nominates a tenant: no token, no session key.
        $this->actingAs($user)->get('/dashboard')->assertStatus(400);
    }

    public function test_session_naming_a_foreign_tenant_is_forbidden(): void
    {
        $tenant = $this->createTenant();
        $foreign = $this->createTenant();
        $user = $this->createMember($tenant, [TenantRole::Admin]);

        $this->actingAs($user)
            ->withSession([ActiveTenantSession::KEY => $foreign->getKey()])
            ->get('/dashboard')
            ->assertForbidden();
    }

    public function test_suspended_membership_is_forbidden(): void
    {
        $tenant = $this->createTenant();
        $user = $this->createUser();
        $this->createMembershipFor($user, $tenant, [TenantRole::Admin], MembershipStatus::Suspended);

        $this->actingAs($user)
            ->withSession([ActiveTenantSession::KEY => $tenant->getKey()])
            ->get('/dashboard')
            ->assertForbidden();
    }
}
