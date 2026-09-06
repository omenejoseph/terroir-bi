<?php

declare(strict_types=1);

namespace App\Actions\Members;

use App\Models\Membership;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * Sets a teammate's password directly, from the Team page's own "Set
 * password" form — a separate, deliberate action from the self-service
 * email-reset flow (App\Http\Controllers\Web\Auth\PasswordResetController):
 * this takes effect immediately, with no confirmation step by the member,
 * which is exactly why it's gated behind `members.manage` and blocked
 * against the actor's own account below — this form is for managing other
 * people's accounts, not your own.
 */
class SetMemberPasswordAction
{
    public function execute(Membership $membership, string $password, User $actor): void
    {
        if ($membership->user_id === $actor->getKey()) {
            throw ValidationException::withMessages([
                'password' => __('iam.set_own_password'),
            ]);
        }

        // user_id is a required, restrict-on-delete foreign key, so this is
        // never actually null at runtime — the guard is for the type system.
        $user = $membership->user;
        if ($user instanceof User) {
            $user->forceFill(['password' => Hash::make($password)])->save();
        }
    }
}
