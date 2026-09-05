<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\Notifications\MarkNotificationsReadAction;
use App\DataTransferObjects\NotificationData;
use App\Http\Controllers\Controller;
use App\Models\Notification;
use App\Models\User;
use App\Queries\ListNotificationsQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    public function index(Request $request, ListNotificationsQuery $query): JsonResponse
    {
        $data = $query->forUser($this->userId($request), $request->boolean('unread'))
            ->map(fn (Notification $n) => NotificationData::fromModel($n)->toArray())
            ->all();

        return response()->json(['data' => $data]);
    }

    /** Mark the given ids read, or all of the user's notifications when none given. */
    public function read(Request $request, MarkNotificationsReadAction $action): JsonResponse
    {
        $ids = $request->input('ids');

        $action->execute($this->userId($request), is_array($ids) ? array_values(array_map('strval', $ids)) : null);

        return response()->json(status: 204);
    }

    private function userId(Request $request): string
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        return $user->getKey();
    }
}
