<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\Localization\LocaleResolver;
use App\Tenancy\Contracts\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sets the application locale per request. Runs after ResolveTenant so the
 * tenant's default locale can participate in resolution.
 */
class SetLocale
{
    public function __construct(
        private readonly LocaleResolver $resolver,
        private readonly TenantContext $tenant,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        app()->setLocale($this->resolver->resolve($request, $this->tenant->current()));

        return $next($request);
    }
}
