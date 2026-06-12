<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\Notifications\DeletePushSubscriptionAction;
use App\Actions\Notifications\RegisterPushSubscriptionAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Notifications\StorePushSubscriptionRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Register / unregister a browser for web push. Thin: validation in the
 * FormRequest, the work in Actions. Keyed to the authenticated (global) user.
 */
class PushSubscriptionController extends Controller
{
    public function store(StorePushSubscriptionRequest $request, RegisterPushSubscriptionAction $action): JsonResponse
    {
        /** @var array{endpoint: string, keys: array{p256dh: string, auth: string}, ua?: string|null} $payload */
        $payload = $request->validated();

        $action->execute($this->user($request), $payload);

        return response()->json(status: 201);
    }

    public function destroy(Request $request, DeletePushSubscriptionAction $action): JsonResponse
    {
        $endpoint = $request->input('endpoint');
        abort_unless(is_string($endpoint) && $endpoint !== '', 422);

        $action->execute($this->user($request), $endpoint);

        return response()->json(status: 204);
    }

    private function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        return $user;
    }
}
