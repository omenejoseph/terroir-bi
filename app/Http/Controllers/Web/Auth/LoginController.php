<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\Auth;

use App\Actions\Auth\StartWebSessionAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Services\Auth\ActiveTenantSession;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Session (cookie) login for the Inertia frontend.
 *
 * The API's token login (Api\Auth\AuthController) is unchanged and still serves
 * the public order/supplier portals and any non-browser client; both go through
 * UserAuthenticator so the credential and tenant rules are shared.
 */
class LoginController extends Controller
{
    public function create(): Response
    {
        return Inertia::render('Auth/Login');
    }

    public function store(LoginRequest $request, StartWebSessionAction $action): RedirectResponse
    {
        $user = $action->execute(
            $request->string('email')->value(),
            $request->string('password')->value(),
            $request->boolean('remember'),
            $request->has('tenant_id') ? $request->string('tenant_id')->value() : null,
        );

        // Platform admins land in the back office, not the tenant dashboard —
        // some hold no tenant membership at all, and /dashboard's tenant.web
        // middleware would 400/403 them. redirect()->intended() still wins
        // when the visit was bounced here from a deep link (e.g. /admin/plans).
        return redirect()->intended($user->is_platform_admin ? '/admin' : '/dashboard');
    }

    public function destroy(
        Request $request,
        AuthFactory $auth,
        ActiveTenantSession $activeTenant,
    ): RedirectResponse {
        $activeTenant->forget();
        $auth->guard('web')->logout();

        // Invalidate rather than just flush: the old session id must not remain
        // usable after sign-out.
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/login');
    }
}
