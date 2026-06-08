<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Tenant;
use App\Tenancy\Contracts\TenantContext;
use App\Tenancy\Contracts\TenantResolver;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Identifies the tenant for a request and binds it into the TenantContext,
 * before any tenant-scoped query runs.
 *
 * Precedence (config('tenant.resolution_order')):
 *   1. token     — authenticated user's tenant_id (once auth is wired up)
 *   2. subdomain — acme.localhost -> tenant by slug
 *   3. header    — X-Tenant dev/test header (non-production only)
 *
 * If a subdomain and an authenticated tenant disagree, the request is rejected.
 */
class ResolveTenant
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly TenantResolver $resolver,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $tenant = null;

        foreach ((array) config('tenant.resolution_order', []) as $strategy) {
            $tenant = match ($strategy) {
                'token' => $this->fromAuth($request),
                'subdomain' => $this->resolver->resolveFromSubdomain($request->getHost()),
                'header' => $this->fromDevHeader($request),
                default => null,
            };

            if ($tenant !== null) {
                break;
            }
        }

        if ($tenant === null) {
            abort(Response::HTTP_NOT_FOUND, 'Tenant could not be identified.');
        }

        // Guard: an authenticated tenant must match a subdomain tenant if both exist.
        $authTenant = $this->fromAuth($request);
        if ($authTenant !== null && $authTenant->getKey() !== $tenant->getKey()) {
            abort(Response::HTTP_FORBIDDEN, 'Tenant mismatch.');
        }

        $this->context->makeCurrent($tenant);

        return $next($request);
    }

    private function fromAuth(Request $request): ?Tenant
    {
        $user = $request->user();

        return $user?->tenant_id ? $this->resolver->resolveById($user->tenant_id) : null;
    }

    private function fromDevHeader(Request $request): ?Tenant
    {
        if (! config('tenant.dev_header_enabled')) {
            return null;
        }

        $id = $request->header((string) config('tenant.dev_header', 'X-Tenant'));

        return $id ? $this->resolver->resolveById($id) : null;
    }
}
