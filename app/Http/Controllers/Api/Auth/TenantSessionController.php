<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Auth;

use App\Actions\Auth\SwitchTenantAction;
use App\DataTransferObjects\TenantMembershipData;
use App\Enums\MembershipStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\SwitchTenantRequest;
use App\Models\Membership;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Lists the authenticated user's tenant memberships and switches the active
 * tenant. These run with auth only (no active tenant required) so a user can
 * switch even before/without an active tenant context.
 */
class TenantSessionController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        $tenants = $user->memberships()
            ->where('status', MembershipStatus::Active->value)
            ->with('tenant')
            ->get()
            ->map(fn (Membership $membership) => TenantMembershipData::fromModel($membership)->toArray())
            ->values();

        return response()->json(['data' => $tenants]);
    }

    public function switch(SwitchTenantRequest $request, SwitchTenantAction $action): JsonResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        $session = $action->execute($user, $request->string('tenant_id')->value());

        return response()->json(['data' => $session->toArray()]);
    }
}
