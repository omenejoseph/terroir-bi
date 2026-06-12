<?php

declare(strict_types=1);

namespace App\Services\Bdd;

use Illuminate\Contracts\Support\Arrayable;
use JsonSerializable;

/**
 * Describes one operation a BDD plan may use: a built-in seed/probe or a
 * granted action class. `parameters` is a simple name => description map (with
 * type/requiredness baked into the description) — enough for the AI compiler
 * to bind arguments and for the admin Access page to render.
 *
 * @implements Arrayable<string, mixed>
 */
final class OperationSpec implements Arrayable, JsonSerializable
{
    /**
     * @param  'seed'|'action'|'probe'  $kind
     * @param  array<string, string>  $parameters
     */
    public function __construct(
        public readonly string $key,
        public readonly string $kind,
        public readonly string $summary,
        public readonly array $parameters = [],
        public readonly bool $requiresGrant = false,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'kind' => $this->kind,
            'summary' => $this->summary,
            'parameters' => $this->parameters,
            'requires_grant' => $this->requiresGrant,
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
