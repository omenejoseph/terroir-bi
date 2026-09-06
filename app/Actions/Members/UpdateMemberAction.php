<?php

declare(strict_types=1);

namespace App\Actions\Members;

use App\DataTransferObjects\MembershipData;
use App\Enums\MembershipStatus;
use App\Enums\TenantRole;
use App\Models\Membership;
use App\Models\User;
use App\Services\Auth\MembershipGuard;
use Illuminate\Validation\ValidationException;

class UpdateMemberAction
{
    public function __construct(private readonly MembershipGuard $guard) {}

    /**
     * `$actor` is optional and only enforces the self-suspend guard when
     * given — the platform-admin backoffice (Web\Admin\TenantMemberController)
     * calls this on behalf of members of OTHER tenants, where "suspending
     * yourself" isn't a coherent risk, so it doesn't pass one.
     *
     * @param  list<TenantRole>|null  $roles
     */
    public function execute(Membership $membership, ?array $roles, ?MembershipStatus $status, ?User $actor = null): MembershipData
    {
        $newRoles = $roles ?? $membership->roles->all();
        $newStatus = $status ?? $membership->status;

        if ($actor !== null && $membership->user_id === $actor->getKey() && $newStatus === MembershipStatus::Suspended) {
            throw ValidationException::withMessages([
                'status' => __('iam.suspend_self'),
            ]);
        }

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
