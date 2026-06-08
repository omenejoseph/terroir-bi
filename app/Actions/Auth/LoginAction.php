<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use App\DataTransferObjects\AuthSessionData;
use App\Enums\MembershipStatus;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Auth\SessionBuilder;
use App\Services\Auth\TokenIssuer;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * Authenticates a user by credentials and issues a token bound to an active
 * tenant. If no tenant is requested, the first active membership is used; a user
 * with no memberships gets a token with no active tenant and must be invited.
 */
class LoginAction
{
    public function __construct(
        private readonly TokenIssuer $tokens,
        private readonly SessionBuilder $sessions,
    ) {}

    public function execute(string $email, string $password, ?string $tenantId = null): AuthSessionData
    {
        $user = User::query()->where('email', $email)->first();

        if ($user === null || ! Hash::check($password, $user->password)) {
            throw ValidationException::withMessages([
                'email' => __('auth.failed'),
            ]);
        }

        $activeTenant = $this->resolveActiveTenant($user, $tenantId);

        $token = $this->tokens->issue($user, $activeTenant);

        return $this->sessions->build($user, $activeTenant, $token);
    }

    private function resolveActiveTenant(User $user, ?string $tenantId): ?Tenant
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
}
