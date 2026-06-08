<?php

declare(strict_types=1);

namespace App\Actions\Invitations;

use App\DataTransferObjects\AuthSessionData;
use App\Enums\MembershipStatus;
use App\Models\Invitation;
use App\Models\Membership;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Auth\SessionBuilder;
use App\Services\Auth\TokenIssuer;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * Accepts an invitation by its plaintext token. For an email with no account, a
 * new user is created from the supplied name/password; an existing account just
 * gains the membership. Returns an authenticated session bound to the tenant.
 *
 * Runs without tenant context (the invitee is not yet a member), so the
 * invitation is looked up via withoutTenant().
 */
class AcceptInvitationAction
{
    public function __construct(
        private readonly TokenIssuer $tokens,
        private readonly SessionBuilder $sessions,
    ) {}

    /**
     * @param  array{first_name?: string|null, middle_name?: string|null, last_name?: string|null}  $profile
     */
    public function execute(string $plainToken, array $profile, ?string $password): AuthSessionData
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
        }

        Membership::firstOrCreate(
            ['tenant_id' => $tenant->getKey(), 'user_id' => $user->getKey()],
            [
                'roles' => $invitation->roles,
                'status' => MembershipStatus::Active,
                'invited_by' => $invitation->invited_by,
                'joined_at' => now(),
            ],
        );

        $invitation->forceFill(['accepted_at' => now()])->save();

        $token = $this->tokens->issue($user, $tenant);

        return $this->sessions->build($user, $tenant, $token);
    }
}
