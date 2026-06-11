<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Defence-in-depth for the back office (and any future admin API): only platform
 * admins pass. Filament's canAccessPanel() is the primary gate; this guards the
 * routes regardless.
 */
class EnsurePlatformAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User || $user->is_platform_admin !== true) {
            abort(Response::HTTP_FORBIDDEN);
        }

        return $next($request);
    }
}
