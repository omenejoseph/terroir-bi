<?php

declare(strict_types=1);

namespace Tests\Feature\Invitations;

use App\Enums\TenantRole;
use App\Models\Invitation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\InteractsWithTenancy;
use Tests\TestCase;

class InvitationTest extends TestCase
{
    use InteractsWithTenancy;
    use RefreshDatabase;

    public function test_admin_can_invite_a_member(): void
    {
        $tenant = $this->createTenant();
        Sanctum::actingAs($this->createMember($tenant, [TenantRole::Admin]));

        $response = $this->postJson('/api/v1/invitations', [
            'email' => 'new@example.com',
            'roles' => ['TEAM', 'CELLAR'],
        ], $this->tenantHeader($tenant))
            ->assertCreated()
            ->assertJsonPath('data.email', 'new@example.com')
            ->assertJsonStructure(['data' => ['id', 'accept_token']]);

        $this->assertIsString($response->json('data.accept_token'));
        $this->assertDatabaseHas('invitations', [
            'tenant_id' => $tenant->getKey(),
            'email' => 'new@example.com',
        ]);
    }

    public function test_non_admin_cannot_invite(): void
    {
        $tenant = $this->createTenant();
        Sanctum::actingAs($this->createMember($tenant, [TenantRole::Team]));

        $this->postJson('/api/v1/invitations', [
            'email' => 'new@example.com',
            'roles' => ['TEAM'],
        ], $this->tenantHeader($tenant))->assertForbidden();
    }

    public function test_cannot_invite_an_existing_member(): void
    {
        $tenant = $this->createTenant();
        $admin = $this->createMember($tenant, [TenantRole::Admin]);
        $existing = $this->createMember($tenant, [TenantRole::Team]);

        Sanctum::actingAs($admin);

        $this->postJson('/api/v1/invitations', [
            'email' => $existing->email,
            'roles' => ['TEAM'],
        ], $this->tenantHeader($tenant))->assertStatus(422);
    }

    public function test_a_new_user_can_accept_an_invitation(): void
    {
        $tenant = $this->createTenant();
        $admin = $this->createMember($tenant, [TenantRole::Admin]);

        Sanctum::actingAs($admin);
        $token = $this->postJson('/api/v1/invitations', [
            'email' => 'jana@example.com',
            'roles' => ['CELLAR'],
        ], $this->tenantHeader($tenant))->json('data.accept_token');

        // Acceptance is unauthenticated.
        $this->postJson('/api/v1/auth/invitations/accept', [
            'token' => $token,
            'first_name' => 'Jana',
            'last_name' => 'Horvat',
            'password' => 'secret-password',
        ])
            ->assertOk()
            ->assertJsonPath('data.active_tenant_id', $tenant->getKey())
            ->assertJsonPath('data.roles', ['CELLAR'])
            ->assertJsonPath('data.user.email', 'jana@example.com')
            ->assertJsonPath('data.user.name', 'Jana Horvat');

        $this->assertDatabaseHas('users', ['email' => 'jana@example.com']);
        $this->assertDatabaseHas('memberships', ['tenant_id' => $tenant->getKey()]);
    }

    public function test_an_existing_user_can_accept_an_invitation(): void
    {
        $tenant = $this->createTenant();
        $admin = $this->createMember($tenant, [TenantRole::Admin]);
        $existing = $this->createUser(['email' => 'existing@example.com']);

        Sanctum::actingAs($admin);
        $token = $this->postJson('/api/v1/invitations', [
            'email' => 'existing@example.com',
            'roles' => ['TEAM'],
        ], $this->tenantHeader($tenant))->json('data.accept_token');

        $this->postJson('/api/v1/auth/invitations/accept', ['token' => $token])
            ->assertOk()
            ->assertJsonPath('data.user.id', $existing->getKey())
            ->assertJsonPath('data.roles', ['TEAM']);
    }

    public function test_invalid_token_is_rejected(): void
    {
        $this->postJson('/api/v1/auth/invitations/accept', ['token' => 'nope'])
            ->assertStatus(422);
    }

    public function test_expired_invitation_is_rejected(): void
    {
        $tenant = $this->createTenant();
        $admin = $this->createMember($tenant, [TenantRole::Admin]);

        Sanctum::actingAs($admin);
        $token = $this->postJson('/api/v1/invitations', [
            'email' => 'late@example.com',
            'roles' => ['TEAM'],
        ], $this->tenantHeader($tenant))->json('data.accept_token');

        // Force-expire it.
        $this->actingAsTenant($tenant);
        Invitation::query()->where('email', 'late@example.com')
            ->update(['expires_at' => now()->subDay()]);
        $this->forgetTenant();

        $this->postJson('/api/v1/auth/invitations/accept', [
            'token' => $token,
            'first_name' => 'Late',
            'last_name' => 'Comer',
            'password' => 'secret-password',
        ])->assertStatus(422);
    }
}
