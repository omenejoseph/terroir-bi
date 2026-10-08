<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Actions\Auth\StartTenantImpersonationAction;
use App\Actions\Tenancy\SetPlatformAdminAction;
use App\Authorization\RoleCapabilities;
use App\Enums\MembershipStatus;
use App\Enums\TenantRole;
use App\Models\AuditLog;
use App\Models\Membership;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Auth\ActiveTenantSession;
use App\Services\Auth\ImpersonationSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\Response;
use Tests\Concerns\InteractsWithTenancy;
use Tests\TestCase;

/**
 * An organisation ADMIN impersonating one of their own team members. The
 * platform-admin flavour (back office, unconfined) is covered by ImpersonationTest.
 */
class TenantImpersonationTest extends TestCase
{
    use InteractsWithTenancy;
    use RefreshDatabase;

    private function membership(User $user, Tenant $tenant): Membership
    {
        return Membership::query()->where('user_id', $user->getKey())->where('tenant_id', $tenant->getKey())->firstOrFail();
    }

    /**
     * @param  list<TenantRole>  $memberRoles
     * @return array{0: Tenant, 1: User, 2: User, 3: Membership} tenant, admin, team member, member's membership
     */
    private function setUpTenant(array $memberRoles = [TenantRole::Team]): array
    {
        $tenant = $this->createTenant();
        $admin = $this->createMember($tenant, [TenantRole::Admin]);
        $member = $this->createMember($tenant, $memberRoles);

        return [$tenant, $admin, $member, $this->membership($member, $tenant)];
    }

    private function asAdmin(User $admin, Tenant $tenant): static
    {
        return $this->actingAs($admin)->withSession([ActiveTenantSession::KEY => $tenant->getKey()]);
    }

    /** @return TestResponse<Response> */
    private function start(User $admin, Tenant $tenant, Membership $target): TestResponse
    {
        return $this->asAdmin($admin, $tenant)->post("/settings/team/{$target->getKey()}/impersonate");
    }

    public function test_an_admin_can_impersonate_a_team_member_and_sees_what_they_see(): void
    {
        [$tenant, $admin, $member, $membership] = $this->setUpTenant([TenantRole::Orders]);

        $this->start($admin, $tenant, $membership)->assertRedirect('/dashboard');

        $this->assertAuthenticatedAs($member);
        $this->assertSame($admin->getKey(), session(ImpersonationSession::KEY));
        $this->assertSame($tenant->getKey(), session(ImpersonationSession::TENANT_KEY));
        $this->get('/dashboard')->assertSuccessful();
        $this->get('/orders')->assertSuccessful();               // the target's own access…
        $this->get('/settings/team')->assertForbidden();         // …and nothing more: no admin powers
    }

    public function test_starting_is_audited_in_the_tenants_own_log(): void
    {
        [$tenant, $admin, $member, $membership] = $this->setUpTenant([TenantRole::Orders]);

        $this->start($admin, $tenant, $membership)->assertRedirect();

        $log = AuditLog::query()->where('action', 'user.impersonation.started')->firstOrFail();
        $this->assertSame($tenant->getKey(), $log->tenant_id);
        $this->assertSame($admin->getKey(), $log->actor_id);
        $this->assertSame($member->getKey(), $log->subject_id);
        $this->assertSame('tenant', ($log->metadata ?? [])['scope'] ?? null);
        $this->assertSame($membership->getKey(), ($log->metadata ?? [])['membership_id'] ?? null);
        $this->assertSame(['ORDERS'], ($log->metadata ?? [])['target_roles'] ?? null);
    }

    public function test_actions_taken_while_impersonating_are_attributed_to_the_admin(): void
    {
        [$tenant, $admin, $member, $membership] = $this->setUpTenant();
        $this->start($admin, $tenant, $membership)->assertRedirect();
        $this->actingAsTenant($tenant);

        app(AuditLogger::class)->record($member, 'order.status_changed');

        $log = AuditLog::query()->where('action', 'order.status_changed')->firstOrFail();
        $this->assertSame($member->getKey(), $log->actor_id);
        $this->assertSame($admin->getKey(), ($log->metadata ?? [])['impersonated_by'] ?? null);
        $this->assertSame($admin->fullName(), ($log->metadata ?? [])['impersonated_by_name'] ?? null);
    }

    public function test_ordinary_audit_entries_carry_no_impersonator(): void
    {
        [$tenant, , $member] = $this->setUpTenant();
        $this->actingAsTenant($tenant);

        app(AuditLogger::class)->record($member, 'order.status_changed');

        $this->assertNull(AuditLog::query()->firstOrFail()->metadata);
    }

    public function test_stopping_returns_to_the_admin_and_the_team_page_and_is_audited(): void
    {
        [$tenant, $admin, $member, $membership] = $this->setUpTenant();
        $this->start($admin, $tenant, $membership)->assertRedirect();
        $this->assertAuthenticatedAs($member);

        $this->post('/impersonation/stop')->assertRedirect('/settings/team');

        $this->assertAuthenticatedAs($admin);
        $this->assertNull(session(ImpersonationSession::KEY));
        $this->assertNull(session(ImpersonationSession::TENANT_KEY));
        $this->assertSame($tenant->getKey(), session(ActiveTenantSession::KEY));
        $this->get('/settings/team')->assertOk();

        $log = AuditLog::query()->where('action', 'user.impersonation.stopped')->firstOrFail();
        $this->assertSame([$admin->getKey(), $member->getKey(), $tenant->getKey(), 'tenant'], [$log->actor_id, $log->subject_id, $log->tenant_id, ($log->metadata ?? [])['scope'] ?? null]);
    }

    public function test_signing_out_while_impersonating_records_the_stop(): void
    {
        [$tenant, $admin, , $membership] = $this->setUpTenant();
        $this->start($admin, $tenant, $membership)->assertRedirect();

        $this->post('/logout')->assertRedirect('/login');

        $this->assertGuest();
        $this->assertSame(1, AuditLog::query()->where('action', 'user.impersonation.stopped')->count());
    }

    public function test_the_session_is_confined_to_the_tenant(): void
    {
        [$tenant, $admin, $member, $membership] = $this->setUpTenant();
        $other = $this->createTenant();
        $this->createMembershipFor($member, $other, [TenantRole::Team]);
        $this->start($admin, $tenant, $membership)->assertRedirect();

        // The tenant switcher is closed…
        $this->post('/tenant/switch', ['tenant_id' => $other->getKey()])->assertForbidden();

        // …and even a session forced onto the target's other tenant is refused.
        $this->withSession([ActiveTenantSession::KEY => $other->getKey()])->get('/dashboard')->assertForbidden();

        // Stopping still works from there.
        $this->post('/impersonation/stop')->assertRedirect('/settings/team');
    }

    public function test_only_an_admin_may_impersonate(): void
    {
        [$tenant, , , $membership] = $this->setUpTenant();
        $manager = $this->createMember($tenant, [TenantRole::Manager]);

        $this->start($manager, $tenant, $membership)->assertForbidden();

        $this->assertAuthenticatedAs($manager);
        $this->assertSame(0, AuditLog::query()->where('action', 'user.impersonation.started')->count());
    }

    /** @return array<string, array{callable(self, Tenant, User, User): Membership, string}> */
    public static function refusals(): array
    {
        return [
            'yourself' => [fn (self $t, Tenant $tenant, User $admin) => $t->membership($admin, $tenant), 'iam.impersonate_self'],
            'another organisation admin' => [fn (self $t, Tenant $tenant) => $t->membership($t->createMember($tenant, [TenantRole::Admin]), $tenant), 'iam.impersonate_tenant_admin'],
            'a member who also holds ADMIN with other roles' => [fn (self $t, Tenant $tenant) => $t->membership($t->createMember($tenant, [TenantRole::Cellar, TenantRole::Admin]), $tenant), 'iam.impersonate_tenant_admin'],
            'a suspended membership' => [fn (self $t, Tenant $tenant) => $t->membership($t->createMember($tenant, [TenantRole::Team], [], MembershipStatus::Suspended), $tenant), 'iam.impersonate_inactive'],
            'a suspended user' => [function (self $t, Tenant $tenant) {
                $user = $t->createMember($tenant, [TenantRole::Team], ['suspended_at' => now()]);
                $user->forceFill(['suspended_at' => now()])->save();

                return $t->membership($user, $tenant);
            }, 'iam.impersonate_suspended'],
            'a platform admin' => [function (self $t, Tenant $tenant) {
                $user = app(SetPlatformAdminAction::class)->execute($t->createMember($tenant, [TenantRole::Team]), true);

                return $t->membership($user, $tenant);
            }, 'iam.impersonate_admin'],
        ];
    }

    /**
     * @param  callable(self, Tenant, User): Membership  $target
     *
     * @dataProvider refusals
     */
    #[DataProvider('refusals')]
    public function test_refusals(callable $target, string $messageKey): void
    {
        $tenant = $this->createTenant();
        $admin = $this->createMember($tenant, [TenantRole::Admin]);
        $membership = $target($this, $tenant, $admin);

        // The request runs in the tenant's locale (hr here), so compare against the same translation.
        $this->start($admin, $tenant, $membership)->assertSessionHasErrors(['user' => __($messageKey)]);

        $this->assertAuthenticatedAs($admin);
        $this->assertNull(session(ImpersonationSession::KEY));
        $this->assertSame(0, AuditLog::query()->where('action', 'user.impersonation.started')->count());
    }

    public function test_cannot_impersonate_a_removed_member(): void
    {
        [$tenant, $admin, , $membership] = $this->setUpTenant();
        $membership->delete();

        // Soft-deleted rows don't resolve through implicit binding at all.
        $this->start($admin, $tenant, $membership)->assertNotFound();
        $this->assertAuthenticatedAs($admin);
    }

    public function test_a_second_impersonation_is_refused_while_one_is_running(): void
    {
        [$tenant, $admin, , $first] = $this->setUpTenant();
        $second = $this->membership($this->createMember($tenant, [TenantRole::Cellar]), $tenant);
        $action = app(StartTenantImpersonationAction::class);

        $this->assertNull($action->denialReason($admin, $second, $tenant));

        $this->start($admin, $tenant, $first)->assertRedirect();

        // Asked from inside a running impersonation (the session still names its admin).
        $this->assertSame(__('iam.impersonate_already_active'), $action->denialReason($admin, $second, $tenant));
    }

    public function test_another_tenants_membership_cannot_be_reached(): void
    {
        [$tenant, $admin] = $this->setUpTenant();
        $elsewhere = $this->createTenant();
        $stranger = $this->membership($this->createMember($elsewhere, [TenantRole::Team]), $elsewhere);

        $this->start($admin, $tenant, $stranger)->assertNotFound();

        $this->assertAuthenticatedAs($admin);
        $this->assertSame(0, AuditLog::query()->where('action', 'user.impersonation.started')->count());
    }

    public function test_a_platform_admin_who_is_not_a_tenant_admin_cannot_use_the_tenant_route(): void
    {
        [$tenant, , , $membership] = $this->setUpTenant();
        $ops = app(SetPlatformAdminAction::class)->execute($this->createMember($tenant, [TenantRole::Cellar]), true);

        $this->start($ops, $tenant, $membership)->assertForbidden();
    }

    public function test_team_page_only_offers_the_button_where_it_will_work(): void
    {
        [$tenant, $admin, $member] = $this->setUpTenant();
        $other = $this->createMember($tenant, [TenantRole::Admin]);
        $suspended = $this->createMember($tenant, [TenantRole::Team], [], MembershipStatus::Suspended);

        $this->asAdmin($admin, $tenant)->get('/settings/team')->assertOk()->assertInertia(function (AssertableInertia $page) use ($admin, $member, $other, $suspended): void {
            $flags = array_column($page->toArray()['props']['members'], 'can_impersonate', 'user_id');

            $this->assertTrue($flags[$member->getKey()]);
            $this->assertFalse($flags[$admin->getKey()], 'not yourself');
            $this->assertFalse($flags[$other->getKey()], 'not another admin');
            $this->assertFalse($flags[$suspended->getKey()], 'not a suspended member');
        });
    }

    public function test_members_view_without_impersonate_never_sees_the_button(): void
    {
        // members.view alone isn't enough: the capability is admin-only.
        $this->assertNotContains('members.impersonate', RoleCapabilities::grants(TenantRole::Manager));
        foreach (TenantRole::cases() as $role) {
            if ($role !== TenantRole::Admin) {
                $this->assertFalse(RoleCapabilities::roleGrants($role, 'members.impersonate'), $role->value);
            }
        }
        $this->assertTrue(RoleCapabilities::roleGrants(TenantRole::Admin, 'members.impersonate'));
    }

    public function test_impersonation_banner_data_is_shared_to_the_page(): void
    {
        [$tenant, $admin, $member, $membership] = $this->setUpTenant();
        $this->start($admin, $tenant, $membership)->assertRedirect();

        $this->get('/dashboard')->assertInertia(fn (AssertableInertia $page) => $page
            ->where('impersonating.impersonator_name', $admin->fullName())
            ->where('impersonating.target_name', $member->fullName()));
    }
}
