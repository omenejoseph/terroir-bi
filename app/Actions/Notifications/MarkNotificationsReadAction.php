<?php

declare(strict_types=1);

namespace App\Actions\Notifications;

use App\Models\Notification;

/**
 * Marks a member's notifications read — the given ids, or every one of theirs
 * when `$ids` is empty/null.
 */
class MarkNotificationsReadAction
{
    /**
     * @param  list<string>|null  $ids
     */
    public function execute(string $userId, ?array $ids): void
    {
        $query = Notification::query()->where('user_id', $userId);

        if ($ids !== null && $ids !== []) {
            $query->whereIn('id', array_map('strval', $ids));
        }

        $query->update(['is_read' => true]);
    }
}
