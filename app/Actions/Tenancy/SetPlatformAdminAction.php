<?php

declare(strict_types=1);

namespace App\Actions\Tenancy;

use App\Models\User;

/**
 * Grants or revokes platform-admin (Filament back office) access. `is_platform_admin`
 * is intentionally not mass-assignable, so this is the only place it is set.
 */
class SetPlatformAdminAction
{
    public function execute(User $user, bool $isAdmin = true): User
    {
        $user->is_platform_admin = $isAdmin;
        $user->save();

        return $user;
    }
}
