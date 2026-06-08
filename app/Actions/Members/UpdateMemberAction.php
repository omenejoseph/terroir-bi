<?php

declare(strict_types=1);

namespace App\Actions\Members;

use App\DataTransferObjects\MembershipData;
use App\Enums\MembershipStatus;
use App\Enums\TenantRole;
use App\Models\Membership;
use App\Services\Auth\MembershipGuard;

class UpdateMemberAction
{
    public function __construct(private readonly MembershipGuard $guard) {}

    /**
     * @param  list<TenantRole>|null  $roles
     */
    public function execute(Membership $membership, ?array $roles, ?MembershipStatus $status): MembershipData
    {
        $newRoles = $roles ?? $membership->roles->all();
        $newStatus = $status ?? $membership->status;

        $remainsActiveAdmin = $newStatus === MembershipStatus::Active
            && in_array(TenantRole::Admin, $newRoles, true);

        $this->guard->ensureNotLastAdmin($membership, $remainsActiveAdmin);

        if ($roles !== null) {
            $membership->roles = collect($roles);
        }

        if ($status !== null) {
            $membership->status = $status;
        }

        $membership->save();

        return MembershipData::fromModel($membership);
    }
}
