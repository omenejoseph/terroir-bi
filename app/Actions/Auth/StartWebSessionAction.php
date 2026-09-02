<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use App\Models\User;
use App\Services\Auth\ActiveTenantSession;
use App\Services\Auth\UserAuthenticator;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Contracts\Auth\StatefulGuard;
use Illuminate\Contracts\Session\Session;
use RuntimeException;

/**
 * Logs a user in for the Inertia frontend: cookie session instead of a bearer
 * token, active tenant recorded in the session.
 *
 * Mirrors LoginAction (the API's token flow) and shares its credential and
 * tenant rules via UserAuthenticator.
 */
class StartWebSessionAction
{
    public function __construct(
        private readonly UserAuthenticator $authenticator,
        private readonly ActiveTenantSession $activeTenant,
        private readonly AuthFactory $auth,
        private readonly Session $session,
    ) {}

    public function execute(string $email, string $password, bool $remember = false, ?string $tenantId = null): User
    {
        $user = $this->authenticator->verify($email, $password);
        $tenant = $this->authenticator->resolveActiveTenant($user, $tenantId);

        $this->guard()->login($user, $remember);

        // Rotate the session id on privilege change (session fixation defence).
        // The tenant is written after regenerating so it survives the rotation.
        $this->session->regenerate();
        $this->activeTenant->set($tenant);

        return $user;
    }

    /**
     * The session guard. StatefulGuard is a contract with no container binding,
     * so it has to come from the auth factory rather than constructor injection.
     */
    private function guard(): StatefulGuard
    {
        $guard = $this->auth->guard('web');

        if (! $guard instanceof StatefulGuard) {
            throw new RuntimeException('The "web" guard must be stateful for session login.');
        }

        return $guard;
    }
}
