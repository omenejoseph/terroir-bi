<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\DataTransferObjects\AuthSessionData;
use App\DataTransferObjects\OrganizationSettingsData;
use App\DataTransferObjects\TenantMembershipData;
use App\DataTransferObjects\UserData;
use App\Enums\MembershipStatus;
use App\Models\Membership;
use App\Models\Tenant;
use App\Models\User;

/**
 * Builds the AuthSessionData returned after login / switch / me, including the
 * user's active-tenant roles and the full list of memberships for a switcher.
 */
class SessionBuilder
{
    public function build(User $user, ?Tenant $activeTenant, ?string $token = null): AuthSessionData
    {
        $memberships = $user->memberships()
            ->where('status', MembershipStatus::Active->value)
            ->with('tenant')
            ->get();

        $roles = [];
        if ($activeTenant !== null) {
            $active = $memberships->firstWhere('tenant_id', $activeTenant->getKey());
            if ($active !== null) {
                $roles = array_values(array_map(fn ($role) => $role->value, $active->roles->all()));
            }
        }

        return new AuthSessionData(
            user: UserData::fromModel($user),
            token: $token,
            activeTenantId: $activeTenant?->getKey(),
            roles: $roles,
            tenants: array_values($memberships
                ->map(fn (Membership $membership) => TenantMembershipData::fromModel($membership))
                ->all()),
            settings: $activeTenant !== null
                ? OrganizationSettingsData::fromTenant($activeTenant)
                : null,
        );
    }
}
