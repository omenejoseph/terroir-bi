<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\DataTransferObjects\AuthSessionData;
use App\DataTransferObjects\OrganizationSettingsData;
use App\DataTransferObjects\TenantMembershipData;
use App\DataTransferObjects\UserData;
use App\Enums\MembershipStatus;
use App\Enums\Module;
use App\Models\Membership;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Billing\TenantAccessResolver;

/**
 * Builds the AuthSessionData returned after login / switch / me, including the
 * user's active-tenant roles, the plan's modules + computed subscription access,
 * and the full list of memberships for a switcher.
 */
class SessionBuilder
{
    public function __construct(private readonly TenantAccessResolver $access) {}

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
            modules: $this->modulesFor($activeTenant),
            access: $activeTenant !== null ? $this->access->for($activeTenant) : null,
        );
    }

    /**
     * The module keys the tenant may use. A tenant with no plan is unrestricted
     * (sees every module), matching the EnforceModuleAccess middleware.
     *
     * @return list<string>
     */
    private function modulesFor(?Tenant $tenant): array
    {
        if ($tenant === null) {
            return [];
        }

        $tenant->loadMissing('plan');

        return $tenant->plan !== null ? $tenant->plan->moduleKeys() : Module::values();
    }
}
