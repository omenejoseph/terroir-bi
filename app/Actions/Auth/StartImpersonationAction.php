<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Auth\ActiveTenantSession;
use App\Services\Auth\ImpersonationSession;
use App\Services\Auth\UserAuthenticator;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Contracts\Auth\StatefulGuard;
use Illuminate\Contracts\Session\Session;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Swaps the session's authenticated user to $target while remembering the
 * acting admin (ImpersonationSession) so "Stop impersonating" can restore
 * them — the same login()+regenerate()+active-tenant sequence
 * StartWebSessionAction uses for a real sign-in, since landing in a target
 * user's tenant context should behave identically either way.
 */
class StartImpersonationAction
{
    public function __construct(
        private readonly UserAuthenticator $authenticator,
        private readonly ActiveTenantSession $activeTenant,
        private readonly ImpersonationSession $impersonation,
        private readonly AuditLogger $audit,
        private readonly AuthFactory $auth,
        private readonly Session $session,
    ) {}

    public function execute(User $admin, User $target): User
    {
        if ($target->is($admin)) {
            throw ValidationException::withMessages(['user' => __('iam.impersonate_self')]);
        }

        if ($target->is_platform_admin) {
            throw ValidationException::withMessages(['user' => __('iam.impersonate_admin')]);
        }

        if ($target->isSuspended()) {
            throw ValidationException::withMessages(['user' => __('iam.impersonate_suspended')]);
        }

        if ($this->impersonation->get() !== null) {
            throw ValidationException::withMessages(['user' => __('iam.impersonate_already_active')]);
        }

        $tenant = $this->authenticator->resolveActiveTenant($target, null);

        $this->impersonation->set($admin->getKey());
        $this->guard()->loginUsingId($target->getKey());
        $this->session->regenerate();
        $this->activeTenant->set($tenant);

        $this->audit->record($admin, 'user.impersonation.started', $target);

        return $target;
    }

    private function guard(): StatefulGuard
    {
        $guard = $this->auth->guard('web');

        if (! $guard instanceof StatefulGuard) {
            throw new RuntimeException('The "web" guard must be stateful for session login.');
        }

        return $guard;
    }
}
