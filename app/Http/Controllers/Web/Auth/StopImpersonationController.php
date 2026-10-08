<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\Auth;

use App\Actions\Auth\StopImpersonationAction;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Auth\ImpersonationSession;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Ends an impersonation and restores the platform admin's own session.
 *
 * Deliberately registered under routes/web.php's plain `auth` group (with
 * logout/tenant-switch), not `platform.admin` or `tenant.web`: while
 * impersonating, the session's authenticated user is the TARGET — an
 * ordinary tenant member, not a platform admin, and not necessarily one
 * ResolveTenant/EnforceTenantAccess would even let through — so this route
 * can only require plain authentication, exactly like TenantSwitchController.
 */
class StopImpersonationController extends Controller
{
    public function store(Request $request, StopImpersonationAction $action, ImpersonationSession $impersonation): RedirectResponse
    {
        $target = $request->user();
        abort_unless($target instanceof User, 401);

        // Read before stopping — it is cleared along with the impersonation.
        $tenantScoped = $impersonation->tenantId() !== null;

        $admin = $action->execute($target);

        if ($admin === null) {
            abort(404);
        }

        // A tenant admin returns to the Team page they started from; a platform admin to the back office.
        return redirect($tenantScoped ? '/settings/team' : '/admin');
    }
}
