<?php

declare(strict_types=1);

namespace App\Actions\Notifications;

use App\Models\Notification;

/** Deletes every one of a member's own notifications. */
class ClearNotificationsAction
{
    public function execute(string $userId): void
    {
        Notification::query()->where('user_id', $userId)->delete();
    }
}
