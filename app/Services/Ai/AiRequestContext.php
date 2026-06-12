<?php

declare(strict_types=1);

namespace App\Services\Ai;

use Closure;

/**
 * Request-scoped holder for the metadata attached to the *next* gateway call.
 * AiClient sets it immediately before invoking a provider and clears it after;
 * App\Providers\AiServiceProvider's global HTTP middleware reads it and emits a
 * `cf-aig-metadata` header so Cloudflare can attribute spend per tenant.
 *
 * Cloudflare allows at most 5 scalar metadata entries per request.
 */
class AiRequestContext
{
    /** @var array<string, scalar> */
    private array $metadata = [];

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function set(array $metadata): void
    {
        $this->metadata = array_slice(
            array_filter($metadata, fn ($v) => $v !== null && is_scalar($v)),
            0,
            5,
            true,
        );
    }

    public function clear(): void
    {
        $this->metadata = [];
    }

    public function has(): bool
    {
        return $this->metadata !== [];
    }

    /**
     * @return array<string, scalar>
     */
    public function metadata(): array
    {
        return $this->metadata;
    }

    /**
     * Run a callback with the given metadata bound, then always clear it.
     *
     * @param  array<string, mixed>  $metadata
     */
    public function tagged(array $metadata, Closure $callback): mixed
    {
        $this->set($metadata);

        try {
            return $callback();
        } finally {
            $this->clear();
        }
    }
}
