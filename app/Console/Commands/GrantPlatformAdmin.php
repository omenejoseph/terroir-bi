<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Tenancy\SetPlatformAdminAction;
use App\Models\User;
use Illuminate\Console\Command;

/**
 * Grants (or revokes) platform-admin access so a user can reach the Filament
 * back office at /admin. Bootstraps the first super-admin.
 */
class GrantPlatformAdmin extends Command
{
    protected $signature = 'admin:grant {email : The user email} {--revoke : Revoke instead of grant}';

    protected $description = 'Grant or revoke Filament back-office (platform admin) access for a user';

    public function handle(SetPlatformAdminAction $action): int
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

        return self::SUCCESS;
    }
}
