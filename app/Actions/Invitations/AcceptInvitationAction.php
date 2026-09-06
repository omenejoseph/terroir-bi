<?php

declare(strict_types=1);

namespace App\Actions\Invitations;

use App\Enums\MembershipStatus;
use App\Models\Invitation;
use App\Models\Membership;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * Accepts an invitation by its plaintext token. For an email with no account, a
 * new user is created from the supplied name/password; an existing account
 * must confirm its own password before gaining the membership — the token
 * proves someone was invited, not that whoever is holding the link IS that
 * existing account, and this is a real web page anyone with the link can
 * open (unlike before, when this only existed as a JSON endpoint nothing
 * actually called). Returns the resulting user + tenant, not a credential —
 * issuing one (a Sanctum token for the API, a web session for the Inertia
 * accept page) is transport-specific and left to the caller, so this one
 * action backs both Api\InvitationController::accept() and
 * Web\Auth\AcceptInvitationController::store().
 *
 * Runs without tenant context (the invitee is not yet a member), so the
 * invitation is looked up via withoutTenant().
 */
class AcceptInvitationAction
{
    /**
     * @param  array{first_name?: string|null, middle_name?: string|null, last_name?: string|null}  $profile
     * @return array{user: User, tenant: Tenant}
     */
    public function execute(string $plainToken, array $profile, ?string $password): array
    {
        $invitation = Invitation::withoutTenant()
            ->where('token', hash('sha256', $plainToken))
            ->first();

        if (! $invitation instanceof Invitation || ! $invitation->isPending()) {
            throw ValidationException::withMessages([
                'token' => __('iam.invitation_invalid'),
            ]);
        }

        $tenant = Tenant::query()->findOrFail($invitation->tenant_id);

        $user = User::query()->where('email', $invitation->email)->first();

        if ($user === null) {
            if (empty($profile['first_name']) || empty($profile['last_name']) || $password === null) {
                throw ValidationException::withMessages([
                    'password' => __('iam.invitation_profile_required'),
                ]);
            }

            $user = User::create([
                'first_name' => $profile['first_name'],
                'middle_name' => $profile['middle_name'] ?? null,
                'last_name' => $profile['last_name'],
                'email' => $invitation->email,
                'password' => Hash::make($password),
            ]);
        } else {
            // Confirms whoever holds this link actually is the existing
            // account, not just someone who came across the token.
            if ($password === null || ! Hash::check($password, $user->password)) {
                throw ValidationException::withMessages([
                    'password' => __('auth.password'),
                ]);
            }
        }

        // withTrashed(): a previously removed-then-reinvited member has a
        // soft-deleted row still occupying the (tenant_id, user_id) unique
        // constraint — firstOrCreate() alone can't see it (the default
        // scope excludes trashed rows) and would collide with it on create.
        $membership = Membership::withTrashed()
            ->where('tenant_id', $tenant->getKey())
            ->where('user_id', $user->getKey())
            ->first();

        if ($membership instanceof Membership) {
            if ($membership->trashed()) {
                $membership->restore();
            }
            $membership->forceFill([
                'roles' => $invitation->roles,
                'status' => MembershipStatus::Active,
                'invited_by' => $invitation->invited_by,
                'joined_at' => now(),
            ])->save();
        } else {
            Membership::create([
                'tenant_id' => $tenant->getKey(),
                'user_id' => $user->getKey(),
                'roles' => $invitation->roles,
                'status' => MembershipStatus::Active,
                'invited_by' => $invitation->invited_by,
                'joined_at' => now(),
            ]);
        }

        $invitation->forceFill(['accepted_at' => now()])->save();

        return ['user' => $user, 'tenant' => $tenant];
    }
}
