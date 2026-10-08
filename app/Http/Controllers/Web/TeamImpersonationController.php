<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Actions\Auth\StartTenantImpersonationAction;
use App\Http\Controllers\Controller;
use App\Models\Membership;
use App\Models\User;
use App\Tenancy\Contracts\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * "Impersonate" on the Team page — capability `members.impersonate` (admin only).
 * Stopping is the shared session route (Web\Auth\StopImpersonationController), which sits
 * outside this group because while impersonating the session user is the target.
 */
class TeamImpersonationController extends Controller
{
    public function store(Request $request, Membership $membership, StartTenantImpersonationAction $action, TenantContext $tenants): RedirectResponse
    {
        $admin = $request->user();
        abort_unless($admin instanceof User, 401);

        // Membership isn't tenant-scoped by a global scope (see its docblock), so a
        // membership id from another tenant must 404 rather than be acted on.
        abort_unless($membership->tenant_id === $tenants->id(), 404);

        $tenant = $tenants->current();
        abort_if($tenant === null, 400);

        $action->execute($admin, $membership->loadMissing('user'), $tenant);

        return redirect('/dashboard');
    }
}
