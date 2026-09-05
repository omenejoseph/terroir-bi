<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Ends a suspended user's session immediately, mid-visit — not just at the
 * next login. Applied to the session-guarded groups (`tenant.web`,
 * `platform.admin`); the token-authenticated `tenant` (Sanctum) group doesn't
 * need it, since SetUserSuspendedAction revokes every token on suspend, which
 * Sanctum's own guard already rejects on its own.
 */
class EnsureUserNotSuspended
{
    public function __construct(private readonly AuthFactory $auth) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user instanceof User && $user->isSuspended()) {
            $this->auth->guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect('/login')->with('error', __('auth.suspended'));
        }

        return $next($request);
    }
}
