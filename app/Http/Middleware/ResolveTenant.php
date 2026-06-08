<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Authorization\MembershipContext;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\Contracts\TenantContext;
use App\Tenancy\Contracts\TenantResolver;
use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

/**
 * Establishes the active tenant for an authenticated request and verifies the
 * user is an active member of it, before any tenant-scoped query runs.
 *
 * Active-tenant precedence (config('tenant.resolution_order')):
 *   1. token     — the Sanctum token's bound tenant_id (set at login / switch)
 *   2. header    — X-Tenant (membership is still verified, so it is safe)
 *   3. subdomain — acme.localhost -> tenant by slug
 *
 * Security: the client never selects a tenant it cannot prove membership of.
 * No active membership ⇒ 403. Must run after auth:sanctum.
 */
class ResolveTenant
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly TenantResolver $resolver,
        private readonly MembershipContext $membership,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User) {
            abort(Response::HTTP_UNAUTHORIZED);
        }

        $tenant = $this->resolveTenant($request, $user);

        if ($tenant === null) {
            abort(Response::HTTP_BAD_REQUEST, 'No active tenant for this request.');
        }

        $membership = $user->membershipFor($tenant);

        if ($membership === null || ! $membership->isActive()) {
            abort(Response::HTTP_FORBIDDEN, 'You are not an active member of this tenant.');
        }

        $this->context->makeCurrent($tenant);

        $membership->setRelation('user', $user);
        $membership->setRelation('tenant', $tenant);
        $this->membership->set($membership);

        return $next($request);
    }

    private function resolveTenant(Request $request, User $user): ?Tenant
    {
        foreach ((array) config('tenant.resolution_order', []) as $strategy) {
            $tenant = match ($strategy) {
                'token' => $this->fromToken($user),
                'header' => $this->fromHeader($request),
                'subdomain' => $this->resolver->resolveFromSubdomain($request->getHost()),
                default => null,
            };

            if ($tenant !== null) {
                return $tenant;
            }
        }

        return null;
    }

    private function fromToken(User $user): ?Tenant
    {
        $token = $user->currentAccessToken();

        // Only real stored tokens carry a bound tenant; TransientToken (used by
        // Sanctum::actingAs / cookie auth) does not.
        if (! $token instanceof PersonalAccessToken) {
            return null;
        }

        $tenantId = $token->getAttribute('tenant_id');

        return is_string($tenantId) ? $this->resolver->resolveById($tenantId) : null;
    }

    private function fromHeader(Request $request): ?Tenant
    {
        if (! config('tenant.dev_header_enabled')) {
            return null;
        }

        $id = $request->header((string) config('tenant.dev_header', 'X-Tenant'));

        return is_string($id) && $id !== '' ? $this->resolver->resolveById($id) : null;
    }
}
