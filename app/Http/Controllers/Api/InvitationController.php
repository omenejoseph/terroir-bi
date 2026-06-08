<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\Invitations\AcceptInvitationAction;
use App\Actions\Invitations\InviteMemberAction;
use App\DataTransferObjects\InvitationData;
use App\Enums\TenantRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Invitations\AcceptInvitationRequest;
use App\Http\Requests\Invitations\InviteMemberRequest;
use App\Models\Invitation;
use App\Models\User;
use Illuminate\Http\JsonResponse;

class InvitationController extends Controller
{
    public function index(): JsonResponse
    {
        $invitations = Invitation::query()
            ->whereNull('accepted_at')
            ->latest()
            ->get()
            ->map(fn (Invitation $invitation) => InvitationData::fromModel($invitation)->toArray())
            ->values();

        return response()->json(['data' => $invitations]);
    }

    public function store(InviteMemberRequest $request, InviteMemberAction $action): JsonResponse
    {
        $actor = $request->user();

        $data = $action->execute(
            $request->string('email')->value(),
            array_values(array_map(fn (string $role) => TenantRole::from($role), $request->array('roles'))),
            $actor instanceof User ? $actor : null,
        );

        return response()->json(['data' => $data->toArray()], 201);
    }

    public function destroy(Invitation $invitation): JsonResponse
    {
        // Route-model binding is tenant-scoped (BelongsToTenant), so this can
        // only target an invitation in the current tenant.
        $invitation->delete();

        return response()->json(status: 204);
    }

    public function accept(AcceptInvitationRequest $request, AcceptInvitationAction $action): JsonResponse
    {
        $session = $action->execute(
            $request->string('token')->value(),
            [
                'first_name' => $request->has('first_name') ? $request->string('first_name')->value() : null,
                'middle_name' => $request->has('middle_name') ? $request->string('middle_name')->value() : null,
                'last_name' => $request->has('last_name') ? $request->string('last_name')->value() : null,
            ],
            $request->has('password') ? $request->string('password')->value() : null,
        );

        return response()->json(['data' => $session->toArray()]);
    }
}
