<?php

declare(strict_types=1);

namespace App\Actions\Tenancy;

use App\Enums\TenantRole;
use App\Models\Membership;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Ensures a user holds TenantRole::Admin (the wildcard-capability role — see
 * App\Authorization\RoleCapabilities) on every tenant they're already a
 * member of. Adds the role alongside whatever they already have rather than
 * replacing it — Admin's wildcard makes the others redundant, but not wrong
 * to keep. A user with no memberships at all has nothing to grant here;
 * that's not an error, just nothing to do (this app can't guess which
 * tenant a brand-new account should join).
 */
class GrantTenantAdminAction
{
    /**
     * `total` lets the caller tell "no memberships at all" apart from
     * "already Admin everywhere" — both leave `changed` empty, but they're
     * different things to report back.
     *
     * @return array{changed: list<string>, total: int} tenant_id of every membership actually changed, and how many the user had in total
     */
    public function execute(User $user): array
    {
        /** @var Collection<int, Membership> $memberships */
        $memberships = Membership::query()->where('user_id', $user->getKey())->get();

        $changed = [];
        foreach ($memberships as $membership) {
            if ($membership->hasRole(TenantRole::Admin)) {
                continue;
            }

            $membership->roles = $membership->roles->push(TenantRole::Admin);
            $membership->save();
            $changed[] = $membership->tenant_id;
        }

        return ['changed' => $changed, 'total' => $memberships->count()];
    }
}
