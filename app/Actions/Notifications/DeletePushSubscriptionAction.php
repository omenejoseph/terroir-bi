<?php

declare(strict_types=1);

namespace App\Actions\Notifications;

use App\Models\PushSubscription;
use App\Models\User;

/**
 * Removes a user's push subscription by endpoint (called when a device opts out).
 * Scoped to the user so one cannot delete another's subscription.
 */
class DeletePushSubscriptionAction
{
    public function execute(User $user, string $endpoint): void
    {
        PushSubscription::query()
            ->where('user_id', $user->getKey())
            ->where('endpoint', $endpoint)
            ->delete();
    }
}
