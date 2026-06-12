<?php

declare(strict_types=1);

namespace App\Services\Notifications;

use App\DataTransferObjects\PushMessageData;
use App\Models\PushSubscription;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;

/**
 * The single boundary to the Web Push SDK — everything else speaks our own types
 * (mirrors how StripeGateway is the sole Stripe boundary). Mock THIS in tests via
 * a FakeWebPushSender; the SDK and the network are never exercised there.
 *
 * Delivery is encrypted per-device with the browser's p256dh/auth keys (RFC 8291)
 * and authenticated with VAPID (RFC 8292). Subscriptions the push service reports
 * as gone (404/410) are pruned so we stop retrying dead devices.
 */
class WebPushSender
{
    /** Whether VAPID keys are configured at all (no network call). */
    public function isConfigured(): bool
    {
        $config = config('services.webpush');

        return is_array($config)
            && is_string($config['public_key'] ?? null) && $config['public_key'] !== ''
            && is_string($config['private_key'] ?? null) && $config['private_key'] !== '';
    }

    /**
     * Send a payload to every device the user has subscribed. Best-effort: a dead
     * subscription is deleted, a transient failure is simply dropped (the durable
     * in-app feed remains the source of truth).
     */
    public function sendToUser(string $userId, PushMessageData $message): void
    {
        if (! $this->isConfigured()) {
            return;
        }

        $subscriptions = PushSubscription::query()->where('user_id', $userId)->get();

        if ($subscriptions->isEmpty()) {
            return;
        }

        $webPush = $this->client();
        $payload = (string) json_encode($message);

        /** @var array<string, PushSubscription> $byEndpoint */
        $byEndpoint = [];
        foreach ($subscriptions as $subscription) {
            $byEndpoint[$subscription->endpoint] = $subscription;
            $webPush->queueNotification($this->toSubscription($subscription), $payload);
        }

        foreach ($webPush->flush() as $report) {
            if ($report->isSuccess()) {
                continue;
            }

            // The push service says this device is gone for good → stop bothering.
            if ($report->isSubscriptionExpired()) {
                $stale = $byEndpoint[$report->getEndpoint()] ?? null;
                $stale?->delete();
            }
        }
    }

    private function client(): WebPush
    {
        $config = (array) config('services.webpush');

        return new WebPush([
            'VAPID' => [
                'subject' => (string) ($config['subject'] ?? config('app.url')),
                'publicKey' => (string) ($config['public_key'] ?? ''),
                'privateKey' => (string) ($config['private_key'] ?? ''),
            ],
        ]);
    }

    private function toSubscription(PushSubscription $subscription): Subscription
    {
        return Subscription::create([
            'endpoint' => $subscription->endpoint,
            'publicKey' => $subscription->p256dh,
            'authToken' => $subscription->auth,
            'contentEncoding' => 'aes128gcm',
        ]);
    }
}
