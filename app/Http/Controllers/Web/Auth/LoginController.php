<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\Auth;

use App\Actions\Auth\StartWebSessionAction;
use App\Actions\Auth\StopImpersonationAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Models\User;
use App\Services\Auth\ActiveTenantSession;
use App\Services\Auth\ImpersonationSession;
use App\Services\Auth\UserAuthenticator;
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

    public function store(LoginRequest $request, StartWebSessionAction $action, UserAuthenticator $authenticator): RedirectResponse
    {
        $user = $action->execute(
            $request->string('email')->value(),
            $request->string('password')->value(),
            $request->boolean('remember'),
            $request->has('tenant_id') ? $request->string('tenant_id')->value() : null,
        );

        // The app comes first, platform admins included; only one with no organisation to open goes
        // to the back office (the tenant middleware would refuse them at /dashboard). See
        // UserAuthenticator::homePath(). redirect()->intended() still wins when the visit was
        // bounced here from a deep link (e.g. /admin/plans).
        return redirect()->intended($authenticator->homePath($user));
    }

    public function destroy(
        Request $request,
        AuthFactory $auth,
        ActiveTenantSession $activeTenant,
        ImpersonationSession $impersonation,
        StopImpersonationAction $stopImpersonation,
    ): RedirectResponse {
        // Signing out mid-impersonation ends it: record the stop (restoring the admin's own
        // session first) so the audit trail never shows a start with no end.
        $user = $request->user();
        if ($impersonation->get() !== null && $user instanceof User) {
            $stopImpersonation->execute($user);
        }

        $activeTenant->forget();
        $auth->guard('web')->logout();

        // Invalidate rather than just flush: the old session id must not remain
        // usable after sign-out.
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/login');
    }
}
