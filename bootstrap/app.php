<?php

use App\Http\Middleware\EnforceModuleAccess;
use App\Http\Middleware\EnforceTenantAccess;
use App\Http\Middleware\ResolveTenant;
use App\Http\Middleware\SetLocale;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        apiPrefix: 'api',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'tenant.resolve' => ResolveTenant::class,
            'locale' => SetLocale::class,
        ]);

        // Applied to authenticated tenant-facing API routes. Order matters:
        // authenticate, resolve+authorize the tenant, enforce plan modules +
        // subscription access (both need the resolved tenant), then set locale.
        $middleware->group('tenant', [
            'auth:sanctum',
            ResolveTenant::class,
            EnforceModuleAccess::class,
            EnforceTenantAccess::class,
            SetLocale::class,
        ]);

        // Tenant context must be bound before route-model binding resolves any
        // tenant-scoped model (otherwise the scope fails closed during binding).
        $middleware->prependToPriorityList(
            SubstituteBindings::class,
            ResolveTenant::class,
        );
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );
    })->create();
