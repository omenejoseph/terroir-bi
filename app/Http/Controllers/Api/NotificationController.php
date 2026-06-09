<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Notification;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Notification::query()
            ->where('user_id', $this->userId($request))
            ->orderByDesc('created_at')
            ->limit(50);

        if ($request->boolean('unread')) {
            $query->where('is_read', false);
        }

        $data = $query->get()->map(fn (Notification $n) => [
            'id' => $n->getKey(),
            'type' => $n->type->value,
            'title' => $n->title,
            'body' => $n->body,
            'link' => $n->link,
            'is_read' => $n->is_read,
            'created_at' => $n->created_at?->toIso8601String(),
        ])->all();

        return response()->json(['data' => $data]);
    }

    /** Mark the given ids read, or all of the user's notifications when none given. */
    public function read(Request $request): JsonResponse
    {
        $query = Notification::query()->where('user_id', $this->userId($request));

        $ids = $request->input('ids');
        if (is_array($ids) && $ids !== []) {
            $query->whereIn('id', array_map('strval', $ids));
        }

        $query->update(['is_read' => true]);

        return response()->json(status: 204);
    }

    private function userId(Request $request): string
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        return $user->getKey();
    }
}
