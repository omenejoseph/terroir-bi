<?php

declare(strict_types=1);

namespace App\DataTransferObjects;

use App\Models\TranslationOverride;
use Illuminate\Contracts\Support\Arrayable;
use JsonSerializable;

/**
 * A transport-agnostic representation of a translation override.
 *
 * DTOs are what Actions and Services return — never Eloquent models or HTTP
 * responses. This lets the same result feed an API Resource, an Inertia page
 * (->toArray()), or a Livewire component without reshaping per transport.
 *
 * @implements Arrayable<string, mixed>
 */
final class TranslationOverrideData implements Arrayable, JsonSerializable
{
    public function __construct(
        public readonly string $id,
        public readonly string $locale,
        public readonly string $key,
        public readonly string $value,
    ) {}

    public static function fromModel(TranslationOverride $override): self
    {
        return new self(
            id: $override->getKey(),
            locale: $override->locale,
            key: $override->key,
            value: $override->value,
        );
    }

    /**
     * @return array{id:string, locale:string, key:string, value:string}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'locale' => $this->locale,
            'key' => $this->key,
            'value' => $this->value,
        ];
    }

    /**
     * @return array{id:string, locale:string, key:string, value:string}
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
