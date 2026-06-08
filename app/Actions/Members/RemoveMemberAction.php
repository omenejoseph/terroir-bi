<?php

declare(strict_types=1);

namespace App\Actions\Members;

use App\Models\Membership;
use App\Models\User;
use App\Services\Auth\MembershipGuard;
use Illuminate\Validation\ValidationException;

class RemoveMemberAction
{
    public function __construct(private readonly MembershipGuard $guard) {}

    public function execute(User $actor, Membership $membership): void
    {
        if ($membership->user_id === $actor->getKey()) {
            throw ValidationException::withMessages([
                'membership' => __('iam.remove_self'),
            ]);
        }

        $this->guard->ensureNotLastAdmin($membership, false);

        $membership->delete();
    }
}
