<?php

declare(strict_types=1);

namespace App\DataTransferObjects;

use App\Models\Notification;
use Illuminate\Contracts\Support\Arrayable;
use JsonSerializable;

/**
 * One row of a member's notification feed. Shared by Api\NotificationController
 * and Web\NotificationController so the two envelopes cannot disagree about
 * what a notification looks like.
 *
 * @implements Arrayable<string, mixed>
 */
final class NotificationData implements Arrayable, JsonSerializable
{
    /**
     * @param  array<string, string>  $data
     */
    public function __construct(
        public readonly string $id,
        public readonly string $type,
        public readonly string $title,
        public readonly ?string $body,
        public readonly array $data,
        public readonly bool $isRead,
        public readonly ?string $createdAt,
    ) {}

    public static function fromModel(Notification $notification): self
    {
        return new self(
            id: $notification->getKey(),
            type: $notification->type->value,
            title: $notification->title,
            body: $notification->body,
            data: $notification->data ?? [],
            isRead: $notification->is_read,
            createdAt: $notification->created_at?->toIso8601String(),
        );
    }

    /**
     * `data` is cast to an object so an empty bag serialises as `{}`, not
     * `[]` — the frontend always gets a dictionary it can index by key.
     *
     * @return array{id: string, type: string, title: string, body: ?string, data: object, is_read: bool, created_at: ?string}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'title' => $this->title,
            'body' => $this->body,
            'data' => (object) $this->data,
            'is_read' => $this->isRead,
            'created_at' => $this->createdAt,
        ];
    }

    /**
     * @return array{id: string, type: string, title: string, body: ?string, data: object, is_read: bool, created_at: ?string}
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
