<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\Members\RemoveMemberAction;
use App\Actions\Members\UpdateMemberAction;
use App\DataTransferObjects\MembershipData;
use App\Enums\MembershipStatus;
use App\Enums\TenantRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Members\UpdateMemberRequest;
use App\Models\Membership;
use App\Models\User;
use App\Tenancy\Contracts\TenantContext;
use Illuminate\Http\JsonResponse;

class MemberController extends Controller
{
    public function index(TenantContext $tenant): JsonResponse
    {
        $members = Membership::query()
            ->where('tenant_id', $tenant->id())
            ->with('user')
            ->get()
            ->map(fn (Membership $membership) => MembershipData::fromModel($membership)->toArray())
            ->values();

        return response()->json(['data' => $members]);
    }

    public function update(
        UpdateMemberRequest $request,
        User $user,
        UpdateMemberAction $action,
        TenantContext $tenant,
    ): JsonResponse {
        $membership = $this->membershipOrFail($user, $tenant);

        $roles = $request->has('roles')
            ? array_values(array_map(fn (string $role) => TenantRole::from($role), $request->array('roles')))
            : null;

        $status = $request->has('status')
            ? MembershipStatus::from($request->string('status')->value())
            : null;

        $data = $action->execute($membership, $roles, $status);

        return response()->json(['data' => $data->toArray()]);
    }

    public function destroy(
        User $user,
        RemoveMemberAction $action,
        TenantContext $tenant,
    ): JsonResponse {
        $membership = $this->membershipOrFail($user, $tenant);
        $actor = request()->user();
        abort_unless($actor instanceof User, 401);

        $action->execute($actor, $membership);

        return response()->json(status: 204);
    }

    private function membershipOrFail(User $user, TenantContext $tenant): Membership
    {
        $membership = Membership::query()
            ->where('tenant_id', $tenant->id())
            ->where('user_id', $user->getKey())
            ->with('user')
            ->first();

        abort_if($membership === null, 404);

        return $membership;
    }
}
