<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Actions\Members\RemoveMemberAction;
use App\Actions\Members\RestoreMemberAction;
use App\Actions\Members\SetMemberPasswordAction;
use App\Actions\Members\UpdateMemberAction;
use App\DataTransferObjects\InvitationData;
use App\DataTransferObjects\MembershipData;
use App\Enums\MembershipStatus;
use App\Enums\TenantRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Members\SetMemberPasswordRequest;
use App\Http\Requests\Members\UpdateMemberRequest;
use App\Models\Invitation;
use App\Models\Membership;
use App\Models\User;
use App\Tenancy\Contracts\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Tenant self-service team management (nav's "System · Team", capability
 * `members.view`/`members.manage`) — under Settings alongside organisation
 * settings, rather than a page of its own, per the "Team" module's own
 * placement. Every write goes through the same Actions the platform-admin
 * backoffice's own member management already uses
 * (Web\Admin\TenantMemberController), so the last-active-admin guard and
 * every other invariant apply identically here.
 *
 * `Membership` is deliberately not tenant-scoped by a global scope (see its
 * own docblock), so every route here re-checks the resolved membership
 * belongs to the CURRENT tenant before acting on it — implicit route-model
 * binding alone would let one tenant admin reach into another tenant's
 * membership by id otherwise.
 */
class TeamController extends Controller
{
    public function __construct(private readonly TenantContext $tenant) {}

    public function index(Request $request): Response
    {
        $tenantId = $this->tenant->id();

        $members = Membership::query()
            ->where('tenant_id', $tenantId)
            ->with('user')
            ->get()
            ->map(fn (Membership $m): array => MembershipData::fromModel($m)->toArray())
            ->values();

        $removedMembers = Membership::onlyTrashed()
            ->where('tenant_id', $tenantId)
            ->with('user')
            ->get()
            ->map(fn (Membership $m): array => MembershipData::fromModel($m)->toArray())
            ->values();

        $invitations = Invitation::query()
            ->whereNull('accepted_at')
            ->latest()
            ->get()
            ->map(fn (Invitation $i): array => InvitationData::fromModel($i)->toArray())
            ->values();

        return Inertia::render('Settings/Team/Index', [
            'members' => $members,
            'removedMembers' => $removedMembers,
            'invitations' => $invitations,
            'roleOptions' => array_map(
                fn (TenantRole $role): array => ['value' => $role->value, 'label' => $role->label()],
                TenantRole::cases(),
            ),
            'currentUserId' => $request->user()?->getKey(),
        ]);
    }

    public function update(UpdateMemberRequest $request, Membership $membership, UpdateMemberAction $action): RedirectResponse
    {
        $this->assertBelongsToCurrentTenant($membership);

        $roles = $request->has('roles')
            ? array_values(array_map(fn (string $role): TenantRole => TenantRole::from($role), $request->array('roles')))
            : null;

        $status = $request->has('status')
            ? MembershipStatus::from($request->string('status')->value())
            : null;

        $actor = $request->user();
        abort_unless($actor instanceof User, 401);

        $action->execute($membership, $roles, $status, $actor);

        return back()->with('success', __('Team member updated.'));
    }

    public function setPassword(SetMemberPasswordRequest $request, Membership $membership, SetMemberPasswordAction $action): RedirectResponse
    {
        $this->assertBelongsToCurrentTenant($membership);

        $actor = $request->user();
        abort_unless($actor instanceof User, 401);

        $action->execute($membership, $request->string('password')->value(), $actor);

        return back()->with('success', __('Password updated.'));
    }

    public function destroy(Request $request, Membership $membership, RemoveMemberAction $action): RedirectResponse
    {
        $this->assertBelongsToCurrentTenant($membership);

        $actor = $request->user();
        abort_unless($actor instanceof User, 401);

        $action->execute($actor, $membership);

        return back()->with('success', __('Team member removed.'));
    }

    /** {membership} here resolves a soft-deleted row — see the withTrashed() route. */
    public function restore(Membership $membership, RestoreMemberAction $action): RedirectResponse
    {
        $this->assertBelongsToCurrentTenant($membership);

        $action->execute($membership);

        return back()->with('success', __('Team member restored — suspended pending a role/status review.'));
    }

    private function assertBelongsToCurrentTenant(Membership $membership): void
    {
        abort_unless($membership->tenant_id === $this->tenant->id(), 404);
    }
}
