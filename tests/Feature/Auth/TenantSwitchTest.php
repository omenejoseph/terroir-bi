<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Enums\TenantRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\InteractsWithTenancy;
use Tests\TestCase;

class TenantSwitchTest extends TestCase
{
    use InteractsWithTenancy;
    use RefreshDatabase;

    public function test_lists_the_users_active_memberships(): void
    {
        $a = $this->createTenant();
        $user = $this->createMember($a, [TenantRole::Admin]);
        $b = $this->createTenant();
        $this->createMembershipFor($user, $b, [TenantRole::Team]);

        Sanctum::actingAs($user);

        $this->getJson('/api/v1/auth/tenants')
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    public function test_switching_tenant_rebinds_context_via_the_new_token(): void
    {
        $a = $this->createTenant();
        $user = $this->createMember($a, [TenantRole::Admin]);
        $b = $this->createTenant();
        $this->createMembershipFor($user, $b, [TenantRole::Team]);

        // Real login to obtain a tenant-bound token.
        $token = $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'password',
        ])->assertOk()->json('data.token');

        $newToken = $this->withToken($token)
            ->postJson('/api/v1/auth/switch-tenant', ['tenant_id' => $b->getKey()])
            ->assertOk()
            ->assertJsonPath('data.active_tenant_id', $b->getKey())
            ->assertJsonPath('data.roles', ['TEAM'])
            ->json('data.token');

        // Simulate a fresh request (the auth guard caches the user within a test).
        $this->app['auth']->forgetGuards();

        // The new token resolves to tenant B without an explicit header.
        $this->withToken($newToken)
            ->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('data.active_tenant_id', $b->getKey());
    }

    public function test_cannot_switch_to_a_tenant_without_membership(): void
    {
        $user = $this->createMember($this->createTenant());
        $other = $this->createTenant();
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/auth/switch-tenant', ['tenant_id' => $other->getKey()])
            ->assertForbidden();
    }
}
