<?php

declare(strict_types=1);

namespace App\Services\Ai;

use App\Enums\AiCapability;

/**
 * Outcome of a live capability health check run from the back office. A
 * capability may not be saved/enabled until its test passes.
 */
final class CapabilityTestResult
{
    public function __construct(
        public readonly AiCapability $capability,
        public readonly string $model,
        public readonly bool $ok,
        public readonly ?string $message = null,
    ) {}

    public static function pass(AiCapability $capability, string $model, ?string $message = null): self
    {
        return new self($capability, $model, true, $message);
    }

    public static function fail(AiCapability $capability, string $model, string $message): self
    {
        return new self($capability, $model, false, $message);
    }

    /**
     * @return array{capability: string, model: string, ok: bool, message: string|null}
     */
    public function toArray(): array
    {
        return [
            'capability' => $this->capability->value,
            'model' => $this->model,
            'ok' => $this->ok,
            'message' => $this->message,
        ];
    }
}
