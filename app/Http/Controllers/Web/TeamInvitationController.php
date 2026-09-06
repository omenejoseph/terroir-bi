<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Actions\Invitations\InviteMemberAction;
use App\Enums\TenantRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Invitations\InviteMemberRequest;
use App\Models\Invitation;
use App\Models\User;
use Illuminate\Http\RedirectResponse;

/**
 * Team page's "Invite" action (capability `invitations.manage`). No email is
 * sent — the plaintext accept link is flashed back once, the same "copy and
 * share it yourself" shape Customers' own "Generate Order Link" already
 * uses, since it can never be re-shown after this request (only the
 * invitation's hashed token is stored).
 */
class TeamInvitationController extends Controller
{
    public function store(InviteMemberRequest $request, InviteMemberAction $action): RedirectResponse
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);

        $data = $action->execute(
            $request->string('email')->value(),
            array_values(array_map(fn (string $role): TenantRole => TenantRole::from($role), $request->array('roles'))),
            $actor,
        );

        return back()
            ->with('success', __('Invitation created.'))
            ->with('invite_link', url('/invitations/'.$data->acceptToken));
    }

    /** Route-model binding is tenant-scoped (BelongsToTenant), so this can only target an invitation in the current tenant. */
    public function destroy(Invitation $invitation): RedirectResponse
    {
        $invitation->delete();

        return back()->with('success', __('Invitation cancelled.'));
    }
}
