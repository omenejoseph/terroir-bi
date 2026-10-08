<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\Auth;

use App\Actions\Auth\SwitchWebSessionTenantAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\SwitchTenantRequest;
use App\Models\User;
use App\Services\Auth\ImpersonationSession;
use Illuminate\Http\RedirectResponse;

/**
 * Session equivalent of Api\Auth\TenantSessionController::switch. Runs with auth
 * only (no active tenant required) so a user can switch even when their current
 * tenant context is unusable.
 */
class TenantSwitchController extends Controller
{
    public function store(SwitchTenantRequest $request, SwitchWebSessionTenantAction $action, ImpersonationSession $impersonation): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        // An organisation admin impersonating a team member stays in that organisation.
        abort_if($impersonation->tenantId() !== null, 403);

        $tenant = $action->execute($user, $request->string('tenant_id')->value());

        // Back to where the switcher was used; the new tenant's data reloads.
        return back()->with('success', __('Switched to :tenant.', ['tenant' => $tenant->name]));
    }
}
