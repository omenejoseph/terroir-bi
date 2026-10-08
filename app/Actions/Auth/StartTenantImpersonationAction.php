<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use App\Models\Membership;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Auth\ActiveTenantSession;
use App\Services\Auth\ImpersonationSession;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Contracts\Auth\StatefulGuard;
use Illuminate\Contracts\Session\Session;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * An organisation admin "logs in as" one of their own team members to see
 * exactly what that person sees (support / role troubleshooting). The
 * tenant-scoped sibling of StartImpersonationAction (which is for platform
 * admins and is unconfined):
 *
 *  - only an ADMIN of the tenant may do it, and only to an ACTIVE member of
 *    that same tenant — never another admin, a platform admin, a suspended or
 *    removed member, themselves, or while another impersonation is running;
 *  - the session is confined to the tenant (ImpersonationSession::tenantId())
 *    so the tenant switcher can't be used to wander into the target's other
 *    organisations — see ResolveTenant;
 *  - it is recorded in the tenant's audit log as `user.impersonation.started`
 *    (and `.stopped`), and every audited action taken while impersonating
 *    carries `impersonated_by` — see AuditLogger.
 */
class StartTenantImpersonationAction
{
    public function __construct(
        private readonly ActiveTenantSession $activeTenant,
        private readonly ImpersonationSession $impersonation,
        private readonly AuditLogger $audit,
        private readonly AuthFactory $auth,
        private readonly Session $session,
    ) {}

    /**
     * Why `$admin` may not impersonate `$target` in `$tenant`, or null when they may.
     * Single source of truth: the Team page uses it to decide which rows offer the
     * button, and execute() enforces it.
     */
    public function denialReason(User $admin, Membership $target, Tenant $tenant): ?string
    {
        $actorMembership = $admin->membershipFor($tenant);
        if ($actorMembership === null || ! $actorMembership->isActive() || ! $actorMembership->isAdmin()) {
            return __('iam.impersonate_not_admin');
        }

        if ($target->tenant_id !== $tenant->getKey()) {
            return __('iam.impersonate_not_member');
        }

        $user = $target->user;

        if ($user === null) {
            return __('iam.impersonate_not_member');
        }

        if ($user->is($admin)) {
            return __('iam.impersonate_self');
        }

        if ($user->is_platform_admin) {
            return __('iam.impersonate_admin');
        }

        if ($target->trashed() || ! $target->isActive() || $user->isSuspended()) {
            return $user->isSuspended() ? __('iam.impersonate_suspended') : __('iam.impersonate_inactive');
        }

        if ($target->isAdmin()) {
            return __('iam.impersonate_tenant_admin');
        }

        if ($this->impersonation->get() !== null) {
            return __('iam.impersonate_already_active');
        }

        return null;
    }

    public function execute(User $admin, Membership $target, Tenant $tenant): User
    {
        $reason = $this->denialReason($admin, $target, $tenant);

        if ($reason !== null) {
            throw ValidationException::withMessages(['user' => $reason]);
        }

        $user = $target->user ?? throw ValidationException::withMessages(['user' => __('iam.impersonate_not_member')]);

        $this->impersonation->set($admin->getKey());
        $this->impersonation->confineToTenant($tenant->getKey());
        $this->guard()->loginUsingId($user->getKey());
        $this->session->regenerate();
        $this->activeTenant->set($tenant);

        $this->audit->record($admin, 'user.impersonation.started', $user, [
            'scope' => 'tenant',
            'membership_id' => $target->getKey(),
            'target_roles' => array_values(array_map(fn ($role) => $role->value, $target->roles->all())),
        ]);

        return $user;
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
