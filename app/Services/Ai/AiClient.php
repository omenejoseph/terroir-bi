<?php

declare(strict_types=1);

namespace App\Services\Ai;

use App\Enums\AiCapability;
use App\Models\AiUsageLog;
use App\Support\Ai\AiModelConfig;
use App\Tenancy\Contracts\TenantContext;
use Illuminate\Support\Facades\Storage;
use Laravel\Ai\Audio;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Files;
use Laravel\Ai\Image;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Transcription;
use Throwable;

use function Laravel\Ai\agent;

/**
 * The single seam over Laravel AI for this app. It:
 *  - resolves the configured model for a capability,
 *  - tags each call with per-tenant metadata (for Cloudflare spend attribution),
 *  - records local usage for fast/offline reporting,
 *  - and runs live capability health checks for the back office.
 *
 * Transport is always the `openai` driver (Lab::OpenAI) — Cloudflare's gateway
 * is OpenAI-compatible — and the real upstream is encoded in the model string
 * (e.g. "openai/gpt-4o-mini", "anthropic/claude-...", "workers-ai/@cf/...").
 */
class AiClient
{
    public function __construct(
        private readonly AiModelConfig $models,
        private readonly AiRequestContext $context,
        private readonly TenantContext $tenant,
    ) {}

    public function enabled(): bool
    {
        return $this->models->enabled();
    }

    public function modelFor(AiCapability $capability): string
    {
        return $this->models->modelFor($capability);
    }

    /**
     * The Laravel AI provider name used as the chat transport — the Cloudflare
     * gateway (OpenAI chat/completions wire format). The real upstream is the
     * "provider/model" string passed as $model.
     */
    private function transport(): string
    {
        return (string) config('ai.transport', 'gateway');
    }

    /**
     * Run an agent prompt for a capability with metadata tagging + usage
     * logging. Returns the raw AgentResponse (use ->toArray() for structured
     * agents, (string) for text).
     *
     * @param  array<int, mixed>  $attachments
     */
    public function prompt(
        Agent $agent,
        string $prompt,
        AiCapability $capability,
        string $feature,
        array $attachments = [],
        ?string $importId = null,
        ?int $timeout = null,
    ): AgentResponse {
        $model = $this->modelFor($capability);
        $tenantId = $this->tenant->currentId();

        $metadata = array_filter([
            'tenant_id' => $tenantId,
            'capability' => $capability->value,
            'feature' => $feature,
            'import_id' => $importId,
        ], fn ($v) => $v !== null);

        try {
            /** @var AgentResponse $response */
            $response = $this->context->tagged($metadata, fn () => $agent->prompt(
                $prompt,
                attachments: $attachments,
                provider: $this->transport(),
                model: $model,
                timeout: $timeout,
            ));
        } catch (Throwable $e) {
            $this->log($capability, $feature, $model, null, false, $tenantId, $importId);
            throw $e;
        }

        $this->log($capability, $feature, $model, $response, true, $tenantId, $importId);

        return $response;
    }

    /**
     * Build the vision attachment(s) for a stored document.
     *
     * Both images AND PDFs are sent as `image_url` parts (data URIs): Cloudflare's
     * chat/completions endpoint rejects OpenAI "file" parts, but accepts a PDF as
     * an image_url, and Gemini reads multi-page PDFs sent this way natively.
     * Returned as an array for future multi-part support.
     *
     * @return array<int, object>
     */
    public function attachmentsFor(string $objectKey, ?string $mime, ?string $disk = null): array
    {
        $disk ??= (string) config('uploads.disk', 'r2');
        $bytes = (string) Storage::disk($disk)->get($objectKey);

        return [Files\Image::fromBase64(base64_encode($bytes), $mime ?: 'application/octet-stream')];
    }

    // --- Capability health checks (live calls; used by the back office) -------

    public function test(AiCapability $capability): CapabilityTestResult
    {
        return match ($capability) {
            AiCapability::Text => $this->testText(),
            AiCapability::Vision => $this->testVision(),
            AiCapability::ImageGeneration => $this->testImageGeneration(),
            AiCapability::AudioGeneration => $this->testAudioGeneration(),
            AiCapability::AudioTranscription => $this->testAudioTranscription(),
        };
    }

    public function testText(): CapabilityTestResult
    {
        $model = $this->modelFor(AiCapability::Text);

        try {
            $response = agent(instructions: 'You are a connectivity health check. Answer tersely.')
                ->prompt('Reply with exactly the word: ok', provider: $this->transport(), model: $model, timeout: 30);

            $this->log(AiCapability::Text, 'health_check', $model, $response, true, $this->tenant->currentId());

            return trim((string) $response) === ''
                ? CapabilityTestResult::fail(AiCapability::Text, $model, 'Empty response from model.')
                : CapabilityTestResult::pass(AiCapability::Text, $model, 'Model responded.');
        } catch (Throwable $e) {
            $this->log(AiCapability::Text, 'health_check', $model, null, false, $this->tenant->currentId());

            return CapabilityTestResult::fail(AiCapability::Text, $model, $e->getMessage());
        }
    }

    public function testVision(): CapabilityTestResult
    {
        $model = $this->modelFor(AiCapability::Vision);

        try {
            $response = agent(instructions: 'You are a vision health check. Answer tersely.')
                ->prompt(
                    'What primary color fills this image? One word.',
                    attachments: [Files\Image::fromBase64(self::RED_SQUARE_PNG, 'image/png')],
                    provider: $this->transport(),
                    model: $model,
                    timeout: 45,
                );

            $this->log(AiCapability::Vision, 'health_check', $model, $response, true, $this->tenant->currentId());

            return trim((string) $response) === ''
                ? CapabilityTestResult::fail(AiCapability::Vision, $model, 'Empty response from model.')
                : CapabilityTestResult::pass(AiCapability::Vision, $model, 'Model described the image.');
        } catch (Throwable $e) {
            $this->log(AiCapability::Vision, 'health_check', $model, null, false, $this->tenant->currentId());

            return CapabilityTestResult::fail(AiCapability::Vision, $model, $e->getMessage());
        }
    }

    public function testImageGeneration(): CapabilityTestResult
    {
        $model = $this->modelFor(AiCapability::ImageGeneration);

        try {
            Image::of('a small solid red square on white')->generate();

            return CapabilityTestResult::pass(AiCapability::ImageGeneration, $model, 'Image generated.');
        } catch (Throwable $e) {
            return CapabilityTestResult::fail(AiCapability::ImageGeneration, $model, $e->getMessage());
        }
    }

    public function testAudioGeneration(): CapabilityTestResult
    {
        $model = $this->modelFor(AiCapability::AudioGeneration);

        try {
            $audio = Audio::of('ok')->generate();

            return ((string) $audio) === ''
                ? CapabilityTestResult::fail(AiCapability::AudioGeneration, $model, 'Empty audio response.')
                : CapabilityTestResult::pass(AiCapability::AudioGeneration, $model, 'Audio synthesized.');
        } catch (Throwable $e) {
            return CapabilityTestResult::fail(AiCapability::AudioGeneration, $model, $e->getMessage());
        }
    }

    public function testAudioTranscription(): CapabilityTestResult
    {
        $model = $this->modelFor(AiCapability::AudioTranscription);

        try {
            // Synthesize a short clip, then transcribe it as a round-trip check.
            $audio = Audio::of('hello')->generate();
            $path = $audio->storeAs('ai-health-check.mp3');

            if (! is_string($path)) {
                return CapabilityTestResult::fail(AiCapability::AudioTranscription, $model, 'Could not store the generated audio.');
            }

            $transcript = Transcription::fromStorage($path)->generate();

            return trim((string) $transcript) === ''
                ? CapabilityTestResult::fail(AiCapability::AudioTranscription, $model, 'Empty transcript.')
                : CapabilityTestResult::pass(AiCapability::AudioTranscription, $model, 'Audio transcribed.');
        } catch (Throwable $e) {
            return CapabilityTestResult::fail(AiCapability::AudioTranscription, $model, $e->getMessage());
        }
    }

    private function log(
        AiCapability $capability,
        string $feature,
        string $model,
        ?AgentResponse $response,
        bool $ok,
        ?string $tenantId,
        ?string $importId = null,
    ): void {
        AiUsageLog::create([
            'tenant_id' => $tenantId,
            'ai_import_id' => $importId,
            'capability' => $capability->value,
            'feature' => $feature,
            'provider' => str_contains($model, '/') ? explode('/', $model, 2)[0] : ($response?->meta->provider),
            'model' => $model,
            'prompt_tokens' => $response?->usage->promptTokens ?? 0,
            'completion_tokens' => $response?->usage->completionTokens ?? 0,
            'cost_usd' => null, // cost is reconciled from the Cloudflare Logs API
            'ok' => $ok,
        ]);
    }

    /** 64×64 solid-red PNG (base64) for the vision health check. Providers
     *  reject tiny 1×1 images as invalid, so this is a real, decodable square. */
    private const RED_SQUARE_PNG = 'iVBORw0KGgoAAAANSUhEUgAAAEAAAABACAIAAAAlC+aJAAAACXBIWXMAAA7EAAAOxAGVKw4bAAAAYklEQVRoge3PMQ0AIADAMEAI/qUgCxEcDcmqYJtn7/GzpQNeNaA1oDWgNaA1oDWgNaA1oDWgNaA1oDWgNaA1oDWgNaA1oDWgNaA1oDWgNaA1oDWgNaA1oDWgNaA1oDWgNaBdQ3UBhKfO+WgAAAAASUVORK5CYII=';
}
