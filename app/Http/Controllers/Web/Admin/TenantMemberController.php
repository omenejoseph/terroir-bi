<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\Admin;

use App\Actions\Tenancy\AddTenantMemberAction;
use App\Enums\MembershipStatus;
use App\Enums\TenantRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Tenants\AddTenantMemberRequest;
use App\Http\Requests\Admin\Tenants\UpdateTenantMemberRequest;
use App\Models\Membership;
use App\Models\Tenant;
use Illuminate\Http\RedirectResponse;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * Port of App\Filament\Resources\Tenants\RelationManagers\MembersRelationManager.
 * "Add member" provisions a fresh user account; edit only ever touches roles +
 * status (a member's identity isn't editable here either) — no dedicated
 * Filament Action existed for that, so update() is a plain model update, same
 * as Filament's default EditAction fell back to.
 */
class TenantMemberController extends Controller
{
    public function store(AddTenantMemberRequest $request, Tenant $tenant, AddTenantMemberAction $action): RedirectResponse
    {
        $action->execute($tenant, $request->validated());

        return back()->with('success', __('Member added.'));
    }

    public function update(UpdateTenantMemberRequest $request, Tenant $tenant, Membership $member): RedirectResponse
    {
        $this->assertBelongsToTenant($tenant, $member);

        $data = $request->validated();

        $member->update([
            'roles' => collect($data['roles'] ?? [])->map(fn (string $role) => TenantRole::from($role)),
            'status' => MembershipStatus::from($data['status']),
        ]);

        return back()->with('success', __('Member updated.'));
    }

    public function destroy(Tenant $tenant, Membership $member): RedirectResponse
    {
        $this->assertBelongsToTenant($tenant, $member);

        $member->delete();

        return back()->with('success', __('Member removed.'));
    }

    private function assertBelongsToTenant(Tenant $tenant, Membership $member): void
    {
        abort_if($member->tenant_id !== $tenant->getKey(), HttpResponse::HTTP_NOT_FOUND);
    }
}
