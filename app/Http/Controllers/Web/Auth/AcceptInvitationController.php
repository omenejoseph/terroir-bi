<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\Auth;

use App\Actions\Invitations\AcceptInvitationAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Invitations\AcceptInvitationRequest;
use App\Models\Invitation;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Auth\ActiveTenantSession;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Contracts\Auth\StatefulGuard;
use Illuminate\Contracts\Session\Session;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

/**
 * Public landing page for a Team invitation link (Web\TeamInvitationController
 * generates the link; no email is sent — the admin shares it directly, the
 * same "Generate Order Link" shape Customers already uses). Guest-only, no
 * tenant context, mirroring how the public order-token page works.
 *
 * Establishes a cookie session (like Web\Auth\LoginController's own
 * StartWebSessionAction), not a Sanctum token — the API's own accept
 * endpoint (Api\InvitationController::accept) is the token-issuing sibling;
 * both call the same App\Actions\Invitations\AcceptInvitationAction for the
 * actual user/membership work, only the credential differs.
 */
class AcceptInvitationController extends Controller
{
    public function show(string $token): Response
    {
        $invitation = Invitation::withoutTenant()
            ->where('token', hash('sha256', $token))
            ->first();

        if (! $invitation instanceof Invitation || ! $invitation->isPending()) {
            return Inertia::render('Auth/AcceptInvitation', ['valid' => false]);
        }

        $tenant = Tenant::query()->find($invitation->tenant_id);
        $hasAccount = User::query()->where('email', $invitation->email)->exists();

        return Inertia::render('Auth/AcceptInvitation', [
            'valid' => true,
            'token' => $token,
            'email' => $invitation->email,
            'tenantName' => $tenant?->name,
            'roleLabels' => array_values(array_map(fn ($role): string => $role->label(), $invitation->roles->all())),
            // An existing account only needs to confirm; a new email needs a
            // name + password to create one — see AcceptInvitationAction.
            'needsProfile' => ! $hasAccount,
        ]);
    }

    public function store(
        AcceptInvitationRequest $request,
        AcceptInvitationAction $action,
        AuthFactory $auth,
        ActiveTenantSession $activeTenant,
        Session $session,
    ): RedirectResponse {
        $result = $action->execute(
            $request->string('token')->value(),
            [
                'first_name' => $request->has('first_name') ? $request->string('first_name')->value() : null,
                'middle_name' => $request->has('middle_name') ? $request->string('middle_name')->value() : null,
                'last_name' => $request->has('last_name') ? $request->string('last_name')->value() : null,
            ],
            $request->has('password') ? $request->string('password')->value() : null,
        );

        $guard = $auth->guard('web');
        if (! $guard instanceof StatefulGuard) {
            throw new RuntimeException('The "web" guard must be stateful for session login.');
        }

        // Rotate the session id on privilege change (session fixation
        // defence), same as StartWebSessionAction — the tenant is written
        // after regenerating so it survives the rotation.
        $guard->login($result['user']);
        $session->regenerate();
        $activeTenant->set($result['tenant']);

        return redirect('/dashboard');
    }
}
