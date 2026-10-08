<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Enums\MembershipStatus;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * Transport-agnostic credential verification and active-tenant selection.
 *
 * Both authentication front-ends share this: the API's token flow
 * (App\Actions\Auth\LoginAction) and the Inertia session flow
 * (App\Actions\Auth\StartWebSessionAction). Keeping the rules here means the
 * two can never drift on what counts as a valid login or a permitted tenant.
 */
class UserAuthenticator
{
    /**
     * Verify email + password.
     *
     * @throws ValidationException when the credentials do not match a user.
     */
    public function verify(string $email, string $password): User
    {
        $user = User::query()->where('email', $email)->first();

        if ($user === null || ! Hash::check($password, $user->password)) {
            throw ValidationException::withMessages([
                'email' => __('auth.failed'),
            ]);
        }

        if ($user->isSuspended()) {
            throw ValidationException::withMessages([
                'email' => __('auth.suspended'),
            ]);
        }

        return $user;
    }

    /**
     * Pick the tenant the session/token should start in.
     *
     * An explicit $tenantId must be one the user is an active member of. With
     * no explicit choice, the first active membership wins; a user with no
     * memberships gets null and must be invited before they can do anything.
     *
     * @throws ValidationException when $tenantId is not an active membership.
     */
    public function resolveActiveTenant(User $user, ?string $tenantId): ?Tenant
    {
        if ($tenantId !== null) {
            $membership = $user->membershipFor($tenantId);

            if ($membership === null || ! $membership->isActive()) {
                throw ValidationException::withMessages([
                    'tenant_id' => __('auth.tenant_membership'),
                ]);
            }

            return Tenant::query()->find($tenantId);
        }

        $first = $user->memberships()
            ->where('status', MembershipStatus::Active->value)
            ->first();

        return $first !== null ? Tenant::query()->find($first->tenant_id) : null;
    }

    /**
     * Where someone lands after signing in (or visiting `/` while signed in).
     *
     * The app comes first: anyone who belongs to an organisation goes to its dashboard, platform
     * admins included. Only a platform admin with no usable organisation goes to the back office,
     * because the app's tenant middleware would refuse them. They reach /admin from the app's
     * footer link whenever they want it.
     */
    public function homePath(User $user): string
    {
        if ($this->resolveActiveTenant($user, null) !== null) {
            return '/dashboard';
        }

        return $user->is_platform_admin ? '/admin' : '/dashboard';
    }
}
