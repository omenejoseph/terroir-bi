<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * Verifies that a user may make a given tenant active.
 *
 * Shared by the API's token switch (App\Actions\Auth\SwitchTenantAction) and
 * the Inertia session switch (App\Actions\Auth\SwitchWebSessionTenantAction),
 * so a client can never select a tenant it cannot prove membership of —
 * whichever transport it arrives on.
 */
class TenantSwitcher
{
    /**
     * @throws AuthorizationException when the user has no active membership.
     */
    public function authorize(User $user, string $tenantId): Tenant
    {
        $membership = $user->membershipFor($tenantId);

        if ($membership === null || ! $membership->isActive()) {
            throw new AuthorizationException('You are not an active member of this tenant.');
        }

        return Tenant::query()->findOrFail($tenantId);
    }
}
