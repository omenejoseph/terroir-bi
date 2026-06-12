<?php

declare(strict_types=1);

namespace App\DataTransferObjects;

use App\Enums\NotificationType;
use Illuminate\Contracts\Support\Arrayable;
use JsonSerializable;

/**
 * The JSON payload delivered to the service worker. It is deliberately
 * path-free: it carries a semantic `type` plus a `data` bag of route params, and
 * each client (web now, native later) maps (type, data) to its own route. `tag`
 * lets the OS collapse duplicates; `icon` is the notification image.
 *
 * @implements Arrayable<string, mixed>
 */
final class PushMessageData implements Arrayable, JsonSerializable
{
    /**
     * @param  array<string, string>  $data
     */
    public function __construct(
        public readonly string $title,
        public readonly ?string $body,
        public readonly NotificationType $type,
        public readonly array $data = [],
        public readonly string $icon = '/icons/logo.png',
        public readonly ?string $tag = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'title' => $this->title,
            'body' => $this->body,
            'type' => $this->type->value,
            'data' => (object) $this->data,
            'icon' => $this->icon,
            'tag' => $this->tag ?? $this->type->value,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
