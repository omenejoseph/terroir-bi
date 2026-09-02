<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use App\DataTransferObjects\AuthSessionData;
use App\Services\Auth\SessionBuilder;
use App\Services\Auth\TokenIssuer;
use App\Services\Auth\UserAuthenticator;

/**
 * Authenticates a user by credentials and issues a token bound to an active
 * tenant. If no tenant is requested, the first active membership is used; a user
 * with no memberships gets a token with no active tenant and must be invited.
 *
 * This is the API (token) front-end. The Inertia session front-end is
 * StartWebSessionAction; both delegate credential and tenant checks to
 * UserAuthenticator so the two cannot drift.
 */
class LoginAction
{
    public function __construct(
        private readonly UserAuthenticator $authenticator,
        private readonly TokenIssuer $tokens,
        private readonly SessionBuilder $sessions,
    ) {}

    public function execute(string $email, string $password, ?string $tenantId = null): AuthSessionData
    {
        $user = $this->authenticator->verify($email, $password);
        $activeTenant = $this->authenticator->resolveActiveTenant($user, $tenantId);

        $token = $this->tokens->issue($user, $activeTenant);

        return $this->sessions->build($user, $activeTenant, $token);
    }
}
