<?php

declare(strict_types=1);

namespace App\Jobs;

use App\DataTransferObjects\PushMessageData;
use App\Services\Notifications\WebPushSender;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Delivers one push payload to every device of a single user, off the request
 * path (web push is external HTTP — never block an order transaction on it). The
 * payload is carried by value, so the job needs no tenant context (push
 * subscriptions are user-global).
 */
class SendWebPushNotification implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly string $userId,
        public readonly PushMessageData $message,
    ) {}

    public function handle(WebPushSender $sender): void
    {
        $sender->sendToUser($this->userId, $this->message);
    }
}
