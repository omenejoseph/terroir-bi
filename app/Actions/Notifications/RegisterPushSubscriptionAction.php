<?php

declare(strict_types=1);

namespace App\Actions\Notifications;

use App\Models\PushSubscription;
use App\Models\User;

/**
 * Upserts a browser's push subscription for a user, keyed by its endpoint (the
 * device identity). Re-subscribing refreshes the encryption keys and touches
 * last_used_at. If the same endpoint somehow belonged to another user (shared
 * device, re-login), it is reassigned to the current user.
 */
class RegisterPushSubscriptionAction
{
    /**
     * @param  array{endpoint: string, keys: array{p256dh: string, auth: string}, ua?: string|null}  $payload
     */
    public function execute(User $user, array $payload): PushSubscription
    {
        return PushSubscription::query()->updateOrCreate(
            ['endpoint' => $payload['endpoint']],
            [
                'user_id' => $user->getKey(),
                'p256dh' => $payload['keys']['p256dh'],
                'auth' => $payload['keys']['auth'],
                'ua' => $payload['ua'] ?? null,
                'last_used_at' => now(),
            ],
        );
    }
}
