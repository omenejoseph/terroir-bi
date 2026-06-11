<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Enums\AccessLevel;
use App\Services\Billing\TenantAccessResolver;
use App\Tenancy\Contracts\TenantContext;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Enforces the tenant's subscription access level on tenant API routes:
 *   - full      → pass
 *   - read_only → safe methods (GET/HEAD/OPTIONS) pass, writes 403
 *   - blocked   → 403 on everything except the always-allowed paths
 *
 * A small allow-list keeps the dashboard (and future tenant billing self-service)
 * reachable so the frontend can render its read-only / blocked screens. Note
 * that auth/me, logout and switch-tenant live outside the tenant group, so they
 * are never gated here. Must run after ResolveTenant.
 */
class EnforceTenantAccess
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly TenantAccessResolver $resolver,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $access = $this->resolver->forCurrent($this->context);

        // No tenant bound (shouldn't happen inside the tenant group) → defer.
        if ($access === null || $access->level === AccessLevel::Full) {
            return $next($request);
        }

        if ($this->isAlwaysAllowed($request)) {
            return $next($request);
        }

        if ($access->level === AccessLevel::ReadOnly && $request->isMethodSafe()) {
            return $next($request);
        }

        $code = $access->level === AccessLevel::Blocked ? 'subscription_blocked' : 'subscription_read_only';

        return new JsonResponse([
            'message' => $access->level === AccessLevel::Blocked
                ? 'Your subscription has lapsed. Renew to restore access.'
                : 'Your subscription is in a read-only grace period. Renew to make changes.',
            'code' => $code,
        ], Response::HTTP_FORBIDDEN);
    }

    private function isAlwaysAllowed(Request $request): bool
    {
        return $request->is('api/v1/dashboard', 'api/v1/billing', 'api/v1/billing/*');
    }
}
