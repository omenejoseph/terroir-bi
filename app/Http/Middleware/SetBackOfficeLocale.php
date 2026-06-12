<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Forces the Filament back office into English, independent of the
 * Croatian-first app locale that the tenant-facing frontend uses. Platform
 * admins get a consistent English UI; the frontend keeps its per-tenant locale.
 */
class SetBackOfficeLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        app()->setLocale((string) config('app.fallback_locale', 'en'));

        return $next($request);
    }
}
