<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Authorization\ModuleRegistry;
use App\Enums\Module;
use App\Tenancy\Contracts\TenantContext;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Hides modules that are not in the tenant's plan. The owning module is inferred
 * from the request path via ModuleRegistry's path-prefix map (so the finance
 * trio — which share the finance.* capabilities — are gated independently), and
 * a 403 `module_not_in_plan` is returned if the plan does not include it.
 *
 * Tenants with no plan assigned are treated as unrestricted (internal/legacy);
 * real SaaS tenants always carry a plan. Must run after ResolveTenant.
 */
class EnforceModuleAccess
{
    public function __construct(private readonly TenantContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        $module = $this->moduleForPath($request);

        // Routes not owned by any module (or unknown) are not gated here.
        if ($module === null) {
            return $next($request);
        }

        $tenant = $this->context->current();
        $plan = $tenant?->plan;

        // No plan ⇒ unrestricted; a plan ⇒ strictly its modules.
        if ($plan !== null && ! $plan->hasModule($module)) {
            return new JsonResponse([
                'message' => 'This module is not included in your plan.',
                'code' => 'module_not_in_plan',
            ], Response::HTTP_FORBIDDEN);
        }

        return $next($request);
    }

    /** Resolve the module that owns the request's first path segment under /api/v1. */
    private function moduleForPath(Request $request): ?Module
    {
        $relative = Str::after($request->path(), 'api/v1/');
        $segment = Str::before($relative, '/');

        if ($segment === '') {
            return null;
        }

        foreach (ModuleRegistry::pathPrefixes() as $module => $prefixes) {
            if (in_array($segment, $prefixes, true)) {
                return Module::from($module);
            }
        }

        return null;
    }
}
