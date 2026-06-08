<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Enums\MembershipStatus;
use App\Enums\TenantRole;
use App\Models\Membership;
use Illuminate\Validation\ValidationException;

/**
 * Invariants that protect a tenant from being locked out or mismanaged.
 */
class MembershipGuard
{
    /**
     * Ensure a tenant always retains at least one active admin after a change to
     * the given membership.
     *
     * @throws ValidationException
     */
    public function ensureNotLastAdmin(Membership $membership, bool $remainsActiveAdmin): void
    {
        if (! $membership->hasRole(TenantRole::Admin) || ! $membership->isActive()) {
            return; // the membership being changed is not currently an active admin
        }

        if ($remainsActiveAdmin) {
            return;
        }

        if ($this->activeAdminCount($membership->tenant_id) <= 1) {
            throw ValidationException::withMessages([
                'roles' => __('iam.last_admin'),
            ]);
        }
    }

    private function activeAdminCount(string $tenantId): int
    {
        return Membership::query()
            ->where('tenant_id', $tenantId)
            ->where('status', MembershipStatus::Active->value)
            ->get()
            ->filter(fn (Membership $m) => $m->hasRole(TenantRole::Admin))
            ->count();
    }
}
