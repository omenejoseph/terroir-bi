<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Actions\Notifications\ClearNotificationsAction;
use App\Actions\Notifications\DeleteNotificationAction;
use App\Actions\Notifications\MarkNotificationsReadAction;
use App\DataTransferObjects\NotificationData;
use App\Http\Controllers\Controller;
use App\Models\Notification;
use App\Models\User;
use App\Queries\ListNotificationsQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The header's notification bell (Figma `230:2478`). Returns JSON rather than
 * an Inertia page — the panel fetches this itself so opening/reading/deleting
 * a notification never triggers a page visit. Shares its logic with
 * Api\NotificationController via the same Query/Actions rather than
 * duplicating it — see docs/17-frontend-screen-plan.md's porting rule.
 */
class NotificationController extends Controller
{
    public function index(Request $request, ListNotificationsQuery $query): JsonResponse
    {
        $data = $query->forUser($this->userId($request))
            ->map(fn (Notification $n) => NotificationData::fromModel($n)->toArray())
            ->all();

        return response()->json(['data' => $data]);
    }

    /** Mark the given ids read, or all of the user's notifications when none given. */
    public function read(Request $request, MarkNotificationsReadAction $action): JsonResponse
    {
        $ids = $request->input('ids');

        $action->execute($this->userId($request), is_array($ids) ? $ids : null);

        return response()->json(status: 204);
    }

    /** Always 204, whether or not the id existed or belonged to this user — an idempotent delete never leaks either. */
    public function destroy(Request $request, string $notification, DeleteNotificationAction $action): JsonResponse
    {
        $action->execute($this->userId($request), $notification);

        return response()->json(status: 204);
    }

    public function clear(Request $request, ClearNotificationsAction $action): JsonResponse
    {
        $action->execute($this->userId($request));

        return response()->json(status: 204);
    }

    private function userId(Request $request): string
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        return $user->getKey();
    }
}
