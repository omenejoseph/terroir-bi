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
use RuntimeException;

/**
 * Restores the admin's own session after an impersonation — the inverse of
 * StartImpersonationAction. Returns null when there is nothing to stop (no
 * impersonation in progress, or the impersonator's account no longer exists),
 * so the controller can 404/redirect without this action ever leaving the
 * session in a half-restored state.
 */
class StopImpersonationAction
{
    public function __construct(
        private readonly UserAuthenticator $authenticator,
        private readonly ActiveTenantSession $activeTenant,
        private readonly ImpersonationSession $impersonation,
        private readonly AuditLogger $audit,
        private readonly AuthFactory $auth,
        private readonly Session $session,
    ) {}

    public function execute(User $target): ?User
    {
        $impersonatorId = $this->impersonation->get();

        if ($impersonatorId === null) {
            return null;
        }

        $admin = User::query()->find($impersonatorId);

        if ($admin === null) {
            $this->impersonation->forget();

            return null;
        }

        $this->guard()->loginUsingId($admin->getKey());
        $this->impersonation->forget();
        $this->session->regenerate();
        // Re-resolve the admin's OWN tenant context exactly as a fresh login
        // would (StartWebSessionAction does the same) rather than just
        // forgetting it: the session still carries the target's tenant id at
        // this point, and a platform admin who also holds a real membership
        // must be able to navigate back into the tenant app afterward — not
        // hit ResolveTenant's "No active tenant for this request." A pure
        // ops-only admin with no memberships resolves to null, same as before.
        $this->activeTenant->set($this->authenticator->resolveActiveTenant($admin, null));

        $this->audit->record($admin, 'user.impersonation.stopped', $target);

        return $admin;
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
