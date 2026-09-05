<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\Admin;

use App\Actions\Members\RemoveMemberAction;
use App\Actions\Members\UpdateMemberAction;
use App\Actions\Tenancy\AddTenantMemberAction;
use App\Enums\MembershipStatus;
use App\Enums\TenantRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Tenants\AddTenantMemberRequest;
use App\Http\Requests\Admin\Tenants\UpdateTenantMemberRequest;
use App\Models\Membership;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * Port of App\Filament\Resources\Tenants\RelationManagers\MembersRelationManager.
 * "Add member" provisions a fresh user account; edit/remove route through the
 * same UpdateMemberAction/RemoveMemberAction the tenant's own self-service
 * Api\MemberController uses — a platform admin gets the same MembershipGuard
 * "keep at least one active admin" protection a tenant's own admins have,
 * rather than a raw model update/delete bypassing it.
 */
class TenantMemberController extends Controller
{
    public function store(AddTenantMemberRequest $request, Tenant $tenant, AddTenantMemberAction $action): RedirectResponse
    {
        $action->execute($tenant, $request->validated());

        return back()->with('success', __('Member added.'));
    }

    public function update(UpdateTenantMemberRequest $request, Tenant $tenant, Membership $member, UpdateMemberAction $action): RedirectResponse
    {
        $this->assertBelongsToTenant($tenant, $member);

        $data = $request->validated();

        $roles = array_values(array_map(
            fn (string $role): TenantRole => TenantRole::from($role),
            $data['roles'] ?? [],
        ));

        $action->execute($member, $roles, MembershipStatus::from($data['status']));

        return back()->with('success', __('Member updated.'));
    }

    public function destroy(Request $request, Tenant $tenant, Membership $member, RemoveMemberAction $action): RedirectResponse
    {
        $this->assertBelongsToTenant($tenant, $member);

        $admin = $request->user();
        abort_unless($admin instanceof User, 401);

        $action->execute($admin, $member);

        return back()->with('success', __('Member removed.'));
    }

    private function assertBelongsToTenant(Tenant $tenant, Membership $member): void
    {
        abort_if($member->tenant_id !== $tenant->getKey(), HttpResponse::HTTP_NOT_FOUND);
    }
}
