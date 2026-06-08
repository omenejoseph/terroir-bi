<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Auth;

use App\Actions\Auth\LoginAction;
use App\Authorization\MembershipContext;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Models\User;
use App\Services\Auth\SessionBuilder;
use App\Tenancy\Contracts\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;

class AuthController extends Controller
{
    public function login(LoginRequest $request, LoginAction $action): JsonResponse
    {
        $session = $action->execute(
            $request->string('email')->value(),
            $request->string('password')->value(),
            $request->has('tenant_id') ? $request->string('tenant_id')->value() : null,
        );

        return response()->json(['data' => $session->toArray()]);
    }

    public function logout(Request $request): JsonResponse
    {
        $token = $request->user()?->currentAccessToken();

        if ($token instanceof PersonalAccessToken) {
            $token->delete();
        }

        return response()->json(status: 204);
    }

    /** Current user, active tenant, and roles in that tenant. */
    public function me(
        Request $request,
        SessionBuilder $sessions,
        TenantContext $tenant,
        MembershipContext $membership,
    ): JsonResponse {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        $session = $sessions->build($user, $tenant->current());

        return response()->json([
            'data' => $session->toArray() + [
                'roles' => array_map(fn ($role) => $role->value, $membership->roles()),
            ],
        ]);
    }
}
