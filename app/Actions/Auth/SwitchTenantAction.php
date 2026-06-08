<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use App\DataTransferObjects\AuthSessionData;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Auth\SessionBuilder;
use App\Services\Auth\TokenIssuer;
use Illuminate\Auth\Access\AuthorizationException;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Switches the authenticated user's active tenant by issuing a new token bound
 * to the target tenant (after verifying membership) and revoking the current
 * one. The caller replaces its stored token with the returned one.
 */
class SwitchTenantAction
{
    public function __construct(
        private readonly TokenIssuer $tokens,
        private readonly SessionBuilder $sessions,
    ) {}

    public function execute(User $user, string $tenantId): AuthSessionData
    {
        $membership = $user->membershipFor($tenantId);

        if ($membership === null || ! $membership->isActive()) {
            throw new AuthorizationException('You are not an active member of this tenant.');
        }

        $tenant = Tenant::query()->findOrFail($tenantId);

        // Revoke the token used to make this request, if it is a stored token.
        $current = $user->currentAccessToken();
        if ($current instanceof PersonalAccessToken) {
            $current->delete();
        }

        $token = $this->tokens->issue($user, $tenant);

        return $this->sessions->build($user, $tenant, $token);
    }
}
