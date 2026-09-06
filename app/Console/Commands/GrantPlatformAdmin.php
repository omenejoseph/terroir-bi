<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Tenancy\GrantTenantAdminAction;
use App\Actions\Tenancy\SetPlatformAdminAction;
use App\Models\User;
use Illuminate\Console\Command;

/**
 * Grants (or revokes) platform-admin access so a user can reach the back
 * office at /admin. Bootstraps the first super-admin.
 *
 * On grant (not on revoke — revoking platform access says nothing about
 * their tenant roles), also ensures TenantRole::Admin — full capabilities,
 * via RoleCapabilities' wildcard — on every tenant they're already a member
 * of, via GrantTenantAdminAction. A brand-new account with no memberships
 * yet has nothing to grant there; run this after they've joined a tenant if
 * that's when full access is meant to take effect.
 */
class GrantPlatformAdmin extends Command
{
    protected $signature = 'admin:grant {email : The user email} {--revoke : Revoke instead of grant}';

    protected $description = 'Grant or revoke back-office (platform admin) access for a user';

    public function handle(SetPlatformAdminAction $action, GrantTenantAdminAction $tenantAdmin): int
    {
        $email = (string) $this->argument('email');
        $user = User::query()->where('email', $email)->first();

        if ($user === null) {
            $this->error("No user with email [{$email}].");

            return self::FAILURE;
        }

        $grant = ! $this->option('revoke');
        $action->execute($user, $grant);

        $this->info(($grant ? 'Granted' : 'Revoked')." platform-admin for {$email}.");

        if ($grant) {
            ['changed' => $changed, 'total' => $total] = $tenantAdmin->execute($user);

            if ($changed !== []) {
                $this->info('Granted TenantRole::Admin on '.count($changed).' of '.$total.' tenant membership(s).');
            } elseif ($total === 0) {
                $this->info('No tenant memberships exist for this user yet — nothing to grant there.');
            } else {
                $this->info("Already Admin on all {$total} tenant membership(s) — nothing to change.");
            }
        }

        return self::SUCCESS;
    }
}
