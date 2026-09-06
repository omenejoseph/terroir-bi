<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Enums\TenantRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\PendingCommand;
use Tests\Concerns\InteractsWithTenancy;
use Tests\TestCase;

/**
 * php artisan admin:grant — App\Console\Commands\GrantPlatformAdmin. Grants
 * both platform-admin access AND TenantRole::Admin on every tenant the user
 * already belongs to (App\Actions\Tenancy\GrantTenantAdminAction).
 */
class GrantPlatformAdminTest extends TestCase
{
    use InteractsWithTenancy;
    use RefreshDatabase;

    public function test_grant_sets_platform_admin_and_tenant_admin_role(): void
    {
        $tenant = $this->createTenant();
        $member = $this->createMember($tenant, [TenantRole::Team]);

        $this->runArtisan('admin:grant', ['email' => $member->email])
            ->expectsOutputToContain('Granted TenantRole::Admin on 1 of 1 tenant membership(s).')
            ->assertExitCode(0);

        $member->refresh();
        $this->assertTrue($member->is_platform_admin);

        $this->actingAsTenant($tenant);
        $membership = $member->memberships()->where('tenant_id', $tenant->getKey())->firstOrFail();
        $this->assertTrue($membership->hasRole(TenantRole::Admin));
        // Existing roles are kept alongside Admin, not replaced.
        $this->assertTrue($membership->hasRole(TenantRole::Team));
        $this->forgetTenant();
    }

    /**
     * The two "nothing changed" cases read differently: this user already
     * holds Admin, so it's "already admin everywhere" — not "no memberships
     * at all" (see test_grant_with_no_memberships_only_sets_platform_admin).
     */
    public function test_grant_reports_already_admin_when_nothing_changes(): void
    {
        $tenant = $this->createTenant();
        $member = $this->createMember($tenant, [TenantRole::Admin]);

        $this->runArtisan('admin:grant', ['email' => $member->email])
            ->expectsOutputToContain('Already Admin on all 1 tenant membership(s) — nothing to change.')
            ->assertExitCode(0);
    }

    /** A user with memberships in two tenants gets Admin on both. */
    public function test_grant_covers_every_tenant_the_user_belongs_to(): void
    {
        $tenantA = $this->createTenant();
        $tenantB = $this->createTenant();
        $user = $this->createUser();
        $this->createMembershipFor($user, $tenantA, [TenantRole::Cellar]);
        $this->createMembershipFor($user, $tenantB, [TenantRole::Sales]);

        $this->runArtisan('admin:grant', ['email' => $user->email])->assertExitCode(0);

        $this->actingAsTenant($tenantA);
        $this->assertTrue($user->memberships()->where('tenant_id', $tenantA->getKey())->firstOrFail()->hasRole(TenantRole::Admin));
        $this->forgetTenant();

        $this->actingAsTenant($tenantB);
        $this->assertTrue($user->memberships()->where('tenant_id', $tenantB->getKey())->firstOrFail()->hasRole(TenantRole::Admin));
        $this->forgetTenant();
    }

    /** A brand-new account with no memberships yet has nothing tenant-side to grant — not an error. */
    public function test_grant_with_no_memberships_only_sets_platform_admin(): void
    {
        $user = $this->createUser();

        $this->runArtisan('admin:grant', ['email' => $user->email])
            ->expectsOutputToContain('No tenant memberships exist for this user yet — nothing to grant there.')
            ->assertExitCode(0);

        $this->assertTrue($user->refresh()->is_platform_admin);
    }

    public function test_revoke_does_not_touch_tenant_roles(): void
    {
        $tenant = $this->createTenant();
        $member = $this->createMember($tenant, [TenantRole::Team]);

        $this->runArtisan('admin:grant', ['email' => $member->email])->assertExitCode(0);
        $this->runArtisan('admin:grant', ['email' => $member->email, '--revoke' => true])->assertExitCode(0);

        $member->refresh();
        $this->assertFalse($member->is_platform_admin);

        // Still Admin from the earlier grant — revoke only affects platform access.
        $this->actingAsTenant($tenant);
        $this->assertTrue($member->memberships()->where('tenant_id', $tenant->getKey())->firstOrFail()->hasRole(TenantRole::Admin));
        $this->forgetTenant();
    }

    public function test_unknown_email_fails(): void
    {
        $this->runArtisan('admin:grant', ['email' => 'nobody@example.test'])
            ->assertExitCode(1);
    }

    /**
     * artisan() is typed PendingCommand|int (a different overload can return
     * a bare exit code) — always PendingCommand for the {command, params}
     * form used throughout this file.
     *
     * @param  array<string, mixed>  $parameters
     */
    private function runArtisan(string $command, array $parameters = []): PendingCommand
    {
        $result = $this->artisan($command, $parameters);
        self::assertInstanceOf(PendingCommand::class, $result);

        return $result;
    }
}
