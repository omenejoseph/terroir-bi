<?php

declare(strict_types=1);

namespace App\Actions\Notifications;

use App\Models\Notification;

/**
 * Deletes one of a member's own notifications. Scoped by `user_id`, not just
 * the model's tenant scope — otherwise one tenant member could delete another
 * member's notification by guessing its id. Idempotent: silently no-ops when
 * the id doesn't exist or isn't the caller's, so a double-click or a stale
 * row in the client's list is never an error.
 */
class DeleteNotificationAction
{
    public function execute(string $userId, string $notificationId): void
    {
        Notification::query()
            ->where('user_id', $userId)
            ->where('id', $notificationId)
            ->delete();
    }
}
