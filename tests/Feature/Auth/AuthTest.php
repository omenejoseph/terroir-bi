<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Enums\TenantRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\InteractsWithTenancy;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use InteractsWithTenancy;
    use RefreshDatabase;

    public function test_login_returns_a_token_and_active_tenant(): void
    {
        $tenant = $this->createTenant();
        $user = $this->createMember($tenant, [TenantRole::Admin]);

        $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'password',
        ])
            ->assertOk()
            ->assertJsonPath('data.active_tenant_id', $tenant->getKey())
            ->assertJsonPath('data.roles', ['ADMIN'])
            ->assertJsonPath('data.user.email', $user->email)
            ->assertJsonStructure(['data' => ['token', 'tenants']]);
    }

    public function test_login_with_wrong_password_is_rejected(): void
    {
        $user = $this->createUser();

        $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'wrong-password',
        ])->assertStatus(422);
    }

    public function test_login_can_select_a_specific_tenant(): void
    {
        $a = $this->createTenant();
        $user = $this->createMember($a, [TenantRole::Admin]);
        $b = $this->createTenant();
        $this->createMembershipFor($user, $b, [TenantRole::Team]);

        $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'password',
            'tenant_id' => $b->getKey(),
        ])
            ->assertOk()
            ->assertJsonPath('data.active_tenant_id', $b->getKey())
            ->assertJsonPath('data.roles', ['TEAM']);
    }

    public function test_login_rejects_a_tenant_the_user_is_not_a_member_of(): void
    {
        $user = $this->createMember($this->createTenant());
        $other = $this->createTenant();

        $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'password',
            'tenant_id' => $other->getKey(),
        ])->assertStatus(422);
    }

    public function test_me_returns_roles_for_the_active_tenant(): void
    {
        $tenant = $this->createTenant();
        $user = $this->createMember($tenant, [TenantRole::Admin, TenantRole::Cellar]);
        Sanctum::actingAs($user);

        $this->getJson('/api/v1/auth/me', $this->tenantHeader($tenant))
            ->assertOk()
            ->assertJsonPath('data.active_tenant_id', $tenant->getKey())
            ->assertJsonPath('data.roles', ['ADMIN', 'CELLAR']);
    }

    public function test_user_with_no_membership_gets_a_token_but_no_active_tenant(): void
    {
        $user = $this->createUser();

        $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'password',
        ])
            ->assertOk()
            ->assertJsonPath('data.active_tenant_id', null)
            ->assertJsonPath('data.tenants', []);
    }
}
