<?php

declare(strict_types=1);

namespace App\Support\Ai;

use App\Enums\AiCapability;
use App\Support\GlobalSettings;

/**
 * Resolves the platform-wide model and on/off state for each AI capability.
 * Enablement is per-capability (each is turned on independently, only after its
 * live health check passes). Live values come from global_settings (managed in
 * the back office); config/ai.php provides the fallback defaults. Models are
 * "provider/model" strings routed through the Cloudflare gateway.
 */
class AiModelConfig
{
    public function __construct(private readonly GlobalSettings $settings) {}

    /** Whether any AI capability is enabled (the module is usable at all). */
    public function enabled(): bool
    {
        foreach (AiCapability::cases() as $capability) {
            if ($this->capabilityEnabled($capability)) {
                return true;
            }
        }

        return false;
    }

    /** Whether a specific capability is enabled platform-wide. */
    public function capabilityEnabled(AiCapability $capability): bool
    {
        return (bool) $this->settings->get('ai.enabled.'.$capability->value, false);
    }

    public function setCapabilityEnabled(AiCapability $capability, bool $enabled): void
    {
        $this->settings->set('ai.enabled.'.$capability->value, $enabled);
    }

    /** The "provider/model" string configured for a capability. */
    public function modelFor(AiCapability $capability): string
    {
        $stored = $this->settings->get($capability->settingKey());

        $model = match (true) {
            is_array($stored) => $stored['model'] ?? null,
            is_string($stored) => $stored,
            default => null,
        };

        return $this->normalize($model ?: (string) config('ai.capabilities.'.$capability->value.'.model'));
    }

    /**
     * Normalise a model id to the "author/model" form Cloudflare's unified REST
     * endpoint requires, so admins can paste either the gateway slug or a bare
     * id copied from a provider's docs. The unified endpoint uses the short
     * author slug ("google/gemini-…", "openai/…", "anthropic/…") — NOT the
     * provider-native "google-ai-studio". Rules:
     *  - "google-ai-studio/…"  → "google/…"   (the provider-native slug isn't valid here)
     *  - a bare id (no "/")     → prefixed by inferring the author from the model family
     *  - anything already "author/model" is left as-is
     */
    private function normalize(string $model): string
    {
        $model = trim($model);

        if (str_contains($model, '/')) {
            return str_starts_with($model, 'google-ai-studio/')
                ? 'google/'.substr($model, strlen('google-ai-studio/'))
                : $model;
        }

        $author = match (true) {
            str_starts_with($model, 'gemini') => 'google',
            str_starts_with($model, 'claude') => 'anthropic',
            str_starts_with($model, 'gpt'),
            str_starts_with($model, 'o1'),
            str_starts_with($model, 'o3'),
            str_starts_with($model, 'o4'),
            str_starts_with($model, 'dall-e'),
            str_starts_with($model, 'whisper'),
            str_starts_with($model, 'tts'),
            str_starts_with($model, 'text-embedding') => 'openai',
            default => null,
        };

        return $author !== null ? $author.'/'.$model : $model;
    }

    public function setModel(AiCapability $capability, string $model): void
    {
        $this->settings->set($capability->settingKey(), ['model' => $model]);
    }

    /**
     * Every capability's current model, keyed by capability value.
     *
     * @return array<string, string>
     */
    public function all(): array
    {
        $models = [];
        foreach (AiCapability::cases() as $capability) {
            $models[$capability->value] = $this->modelFor($capability);
        }

        return $models;
    }
}
