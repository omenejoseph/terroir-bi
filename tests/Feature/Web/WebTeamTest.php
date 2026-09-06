<?php

declare(strict_types=1);

namespace Tests\Feature\Web;

use App\Enums\TenantRole;
use App\Models\Invitation;
use App\Models\Membership;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Auth\ActiveTenantSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Inertia\Testing\AssertableInertia;
use Tests\Concerns\InteractsWithTenancy;
use Tests\TestCase;

/**
 * The Inertia Team page (nav's "System · Team") — self-service member
 * management. Every write goes through the same App\Actions\Members\* the
 * platform-admin backoffice's own member management already uses
 * (tests/Feature/Admin/AdminResourcesTest covers that side), so this is
 * about the page envelope, the capability gates, and the cross-tenant guard
 * `Membership` (not globally tenant-scoped) needs of its own routes.
 */
class WebTeamTest extends TestCase
{
    use InteractsWithTenancy;
    use RefreshDatabase;

    public function test_team_page_lists_members(): void
    {
        $tenant = $this->createTenant();
        $admin = $this->createMember($tenant, [TenantRole::Admin]);
        $this->createMember($tenant, [TenantRole::Team]);

        $this->actingAs($admin)
            ->withSession([ActiveTenantSession::KEY => $tenant->getKey()])
            ->get('/settings/team')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Settings/Team/Index')
                ->has('members', 2)
                ->has('removedMembers', 0)
                ->has('invitations', 0)
                ->has('roleOptions')
                ->where('currentUserId', $admin->getKey()));
    }

    public function test_team_page_is_closed_to_a_non_admin(): void
    {
        $tenant = $this->createTenant();
        $member = $this->createMember($tenant, [TenantRole::Cellar]);

        $this->actingAs($member)
            ->withSession([ActiveTenantSession::KEY => $tenant->getKey()])
            ->get('/settings/team')
            ->assertForbidden();
    }

    public function test_admin_can_update_a_members_roles(): void
    {
        [$tenant, $admin, , $membership] = $this->tenantAdminAndMembership();

        $this->actingAs($admin)
            ->withSession([ActiveTenantSession::KEY => $tenant->getKey()])
            ->patch("/settings/team/{$membership->getKey()}", ['roles' => ['CELLAR']])
            ->assertRedirect();

        $this->actingAsTenant($tenant);
        $membership->refresh();
        $this->assertTrue($membership->hasRole(TenantRole::Cellar));
        $this->forgetTenant();
    }

    public function test_admin_cannot_suspend_their_own_membership(): void
    {
        $tenant = $this->createTenant();
        $admin = $this->createMember($tenant, [TenantRole::Admin]);
        $this->createMember($tenant, [TenantRole::Admin]); // a second admin, so this isn't the last-admin guard firing instead

        $this->actingAsTenant($tenant);
        $membership = Membership::query()->where('tenant_id', $tenant->getKey())->where('user_id', $admin->getKey())->firstOrFail();
        $this->forgetTenant();

        $this->actingAs($admin)
            ->withSession([ActiveTenantSession::KEY => $tenant->getKey()])
            ->patch("/settings/team/{$membership->getKey()}", ['status' => 'suspended'])
            ->assertSessionHasErrors('status');

        $this->actingAsTenant($tenant);
        $membership->refresh();
        $this->assertTrue($membership->isActive());
        $this->forgetTenant();
    }

    public function test_a_membership_from_another_tenant_is_not_reachable(): void
    {
        $tenant = $this->createTenant();
        $admin = $this->createMember($tenant, [TenantRole::Admin]);

        $otherTenant = $this->createTenant();
        $otherMember = $this->createMember($otherTenant, [TenantRole::Team]);
        $this->actingAsTenant($otherTenant);
        $otherMembership = Membership::query()->where('tenant_id', $otherTenant->getKey())->where('user_id', $otherMember->getKey())->firstOrFail();
        $this->forgetTenant();

        $this->actingAs($admin)
            ->withSession([ActiveTenantSession::KEY => $tenant->getKey()])
            ->patch("/settings/team/{$otherMembership->getKey()}", ['roles' => ['CELLAR']])
            ->assertNotFound();
    }

    public function test_admin_can_set_a_members_password(): void
    {
        [$tenant, $admin, $member, $membership] = $this->tenantAdminAndMembership();

        $this->actingAs($admin)
            ->withSession([ActiveTenantSession::KEY => $tenant->getKey()])
            ->patch("/settings/team/{$membership->getKey()}/password", [
                'password' => 'a-new-password',
                'password_confirmation' => 'a-new-password',
            ])
            ->assertRedirect();

        $member->refresh();
        $this->assertTrue(Hash::check('a-new-password', $member->password));
    }

    public function test_admin_cannot_set_their_own_password_here(): void
    {
        $tenant = $this->createTenant();
        $admin = $this->createMember($tenant, [TenantRole::Admin]);

        $this->actingAsTenant($tenant);
        $membership = Membership::query()->where('tenant_id', $tenant->getKey())->where('user_id', $admin->getKey())->firstOrFail();
        $this->forgetTenant();

        $this->actingAs($admin)
            ->withSession([ActiveTenantSession::KEY => $tenant->getKey()])
            ->patch("/settings/team/{$membership->getKey()}/password", [
                'password' => 'a-new-password',
                'password_confirmation' => 'a-new-password',
            ])
            ->assertSessionHasErrors('password');
    }

    public function test_admin_can_remove_and_restore_a_member(): void
    {
        [$tenant, $admin, $member, $membership] = $this->tenantAdminAndMembership();

        $this->actingAs($admin)
            ->withSession([ActiveTenantSession::KEY => $tenant->getKey()])
            ->delete("/settings/team/{$membership->getKey()}")
            ->assertRedirect();

        $this->assertSoftDeleted('memberships', ['id' => $membership->getKey()]);

        $this->actingAs($admin)
            ->withSession([ActiveTenantSession::KEY => $tenant->getKey()])
            ->post("/settings/team/{$membership->getKey()}/restore")
            ->assertRedirect();

        $this->actingAsTenant($tenant);
        // refresh(), not fresh(): it queries without scopes, so it can
        // re-read a row regardless of the soft-delete scope either way.
        $membership->refresh();
        $this->assertNull($membership->deleted_at);
        // Restored suspended, not active — a deliberate second step, see RestoreMemberAction.
        $this->assertFalse($membership->isActive());
        $this->forgetTenant();
    }

    public function test_admin_cannot_remove_themselves(): void
    {
        $tenant = $this->createTenant();
        $admin = $this->createMember($tenant, [TenantRole::Admin]);

        $this->actingAsTenant($tenant);
        $membership = Membership::query()->where('tenant_id', $tenant->getKey())->where('user_id', $admin->getKey())->firstOrFail();
        $this->forgetTenant();

        $this->actingAs($admin)
            ->withSession([ActiveTenantSession::KEY => $tenant->getKey()])
            ->delete("/settings/team/{$membership->getKey()}")
            ->assertSessionHasErrors('membership');
    }

    public function test_admin_can_invite_a_new_member_and_receives_a_one_time_link(): void
    {
        $tenant = $this->createTenant();
        $admin = $this->createMember($tenant, [TenantRole::Admin]);

        $response = $this->actingAs($admin)
            ->withSession([ActiveTenantSession::KEY => $tenant->getKey()])
            ->from('/settings/team')
            ->post('/settings/team/invitations', [
                'email' => 'newhire@example.test',
                'roles' => ['TEAM'],
            ]);

        $response->assertRedirect('/settings/team');
        $this->assertStringContainsString('/invitations/', (string) session('invite_link'));

        $this->actingAsTenant($tenant);
        $this->assertDatabaseHas('invitations', ['email' => 'newhire@example.test']);
        $this->forgetTenant();
    }

    public function test_admin_can_cancel_a_pending_invitation(): void
    {
        $tenant = $this->createTenant();
        $admin = $this->createMember($tenant, [TenantRole::Admin]);

        $this->actingAs($admin)
            ->withSession([ActiveTenantSession::KEY => $tenant->getKey()])
            ->post('/settings/team/invitations', ['email' => 'cancelme@example.test', 'roles' => ['TEAM']]);

        $this->actingAsTenant($tenant);
        $invitation = Invitation::query()->where('email', 'cancelme@example.test')->firstOrFail();
        $this->forgetTenant();

        $this->actingAs($admin)
            ->withSession([ActiveTenantSession::KEY => $tenant->getKey()])
            ->delete("/settings/team/invitations/{$invitation->getKey()}")
            ->assertRedirect();

        $this->assertDatabaseMissing('invitations', ['id' => $invitation->getKey()]);
    }

    /** @return array{0: Tenant, 1: User, 2: User} */
    private function tenantAdminAndMember(): array
    {
        $tenant = $this->createTenant();
        $admin = $this->createMember($tenant, [TenantRole::Admin]);
        $member = $this->createMember($tenant, [TenantRole::Team]);

        return [$tenant, $admin, $member];
    }

    /** @return array{0: Tenant, 1: User, 2: User, 3: Membership} */
    private function tenantAdminAndMembership(): array
    {
        [$tenant, $admin, $member] = $this->tenantAdminAndMember();

        $this->actingAsTenant($tenant);
        $membership = Membership::query()->where('tenant_id', $tenant->getKey())->where('user_id', $member->getKey())->firstOrFail();
        $this->forgetTenant();

        return [$tenant, $admin, $member, $membership];
    }
}
