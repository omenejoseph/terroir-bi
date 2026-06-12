<?php

declare(strict_types=1);

namespace Tests\Support;

use App\DataTransferObjects\PushMessageData;
use App\Models\PushSubscription;
use App\Services\Notifications\WebPushSender;

/**
 * Test double for WebPushSender — records sends and simulates dead-subscription
 * pruning, without the SDK or the network. Bound via
 * `$this->app->instance(WebPushSender::class, $fake)`, mirroring FakeStripeGateway.
 */
class FakeWebPushSender extends WebPushSender
{
    public bool $configured = true;

    /** @var list<array{user_id: string, message: PushMessageData}> */
    public array $sent = [];

    /**
     * Endpoints the fake push service should report as gone (pruned on send).
     *
     * @var list<string>
     */
    public array $expiredEndpoints = [];

    public function isConfigured(): bool
    {
        return $this->configured;
    }

    public function sendToUser(string $userId, PushMessageData $message): void
    {
        if (! $this->configured) {
            return;
        }

        $this->sent[] = ['user_id' => $userId, 'message' => $message];

        if ($this->expiredEndpoints !== []) {
            PushSubscription::query()
                ->where('user_id', $userId)
                ->whereIn('endpoint', $this->expiredEndpoints)
                ->delete();
        }
    }
}
