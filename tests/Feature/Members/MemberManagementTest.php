<?php

declare(strict_types=1);

namespace Tests\Feature\Members;

use App\Enums\TenantRole;
use App\Models\Membership;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\InteractsWithTenancy;
use Tests\TestCase;

class MemberManagementTest extends TestCase
{
    use InteractsWithTenancy;
    use RefreshDatabase;

    public function test_admin_can_list_members(): void
    {
        $tenant = $this->createTenant();
        $admin = $this->createMember($tenant, [TenantRole::Admin]);
        $this->createMember($tenant, [TenantRole::Team]);

        Sanctum::actingAs($admin);

        $this->getJson('/api/v1/members', $this->tenantHeader($tenant))
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    public function test_admin_can_update_member_roles(): void
    {
        $tenant = $this->createTenant();
        $admin = $this->createMember($tenant, [TenantRole::Admin]);
        $member = $this->createMember($tenant, [TenantRole::Team]);

        Sanctum::actingAs($admin);

        $this->patchJson("/api/v1/members/{$member->getKey()}", [
            'roles' => ['CELLAR'],
        ], $this->tenantHeader($tenant))
            ->assertOk()
            ->assertJsonPath('data.roles', ['CELLAR']);
    }

    public function test_admin_can_remove_a_member(): void
    {
        $tenant = $this->createTenant();
        $admin = $this->createMember($tenant, [TenantRole::Admin]);
        $member = $this->createMember($tenant, [TenantRole::Team]);

        Sanctum::actingAs($admin);

        $this->deleteJson("/api/v1/members/{$member->getKey()}", [], $this->tenantHeader($tenant))
            ->assertNoContent();

        $this->assertDatabaseMissing('memberships', [
            'tenant_id' => $tenant->getKey(),
            'user_id' => $member->getKey(),
        ]);
    }

    public function test_admin_cannot_remove_themselves(): void
    {
        $tenant = $this->createTenant();
        $admin = $this->createMember($tenant, [TenantRole::Admin]);

        Sanctum::actingAs($admin);

        $this->deleteJson("/api/v1/members/{$admin->getKey()}", [], $this->tenantHeader($tenant))
            ->assertStatus(422);
    }

    public function test_cannot_demote_the_last_admin(): void
    {
        $tenant = $this->createTenant();
        $admin = $this->createMember($tenant, [TenantRole::Admin]);
        // A second non-admin so there are members, but only one admin.
        $this->createMember($tenant, [TenantRole::Team]);

        Sanctum::actingAs($admin);

        $this->patchJson("/api/v1/members/{$admin->getKey()}", [
            'roles' => ['TEAM'],
        ], $this->tenantHeader($tenant))->assertStatus(422);
    }

    public function test_non_admin_cannot_manage_members(): void
    {
        $tenant = $this->createTenant();
        $member = $this->createMember($tenant, [TenantRole::Team]);
        $other = $this->createMember($tenant, [TenantRole::Cellar]);

        Sanctum::actingAs($member);

        $this->getJson('/api/v1/members', $this->tenantHeader($tenant))->assertForbidden();
        $this->patchJson("/api/v1/members/{$other->getKey()}", [
            'roles' => ['TEAM'],
        ], $this->tenantHeader($tenant))->assertForbidden();
    }

    public function test_member_management_is_isolated_per_tenant(): void
    {
        $a = $this->createTenant();
        $admin = $this->createMember($a, [TenantRole::Admin]);
        $b = $this->createTenant();
        $strangerInB = $this->createMember($b, [TenantRole::Team]);

        Sanctum::actingAs($admin);

        // The B member is not visible/updatable from tenant A.
        $this->patchJson("/api/v1/members/{$strangerInB->getKey()}", [
            'roles' => ['ADMIN'],
        ], $this->tenantHeader($a))->assertNotFound();

        $this->assertTrue(
            Membership::query()
                ->where('tenant_id', $b->getKey())
                ->where('user_id', $strangerInB->getKey())
                ->first()
                ?->hasRole(TenantRole::Team)
        );
    }
}
