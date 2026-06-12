<?php

/*
|--------------------------------------------------------------------------
| Cloudflare AI Gateway (single entry point)
|--------------------------------------------------------------------------
|
| All inference is routed through Cloudflare AI Gateway's REST API:
|
|   POST {api_base}/accounts/{account}/ai/v1/chat/completions
|   Authorization: Bearer {CLOUDFLARE_API_TOKEN}
|   cf-aig-gateway-id: {gateway}
|
| This endpoint is OpenAI-compatible, so we use Laravel AI's `openai` driver
| purely as the transport and select the *real* provider+model per call with a
| "provider/model" string (e.g. openai/gpt-4o-mini, anthropic/claude-..., or
| workers-ai/@cf/...). Provider keys are stored in the Cloudflare dashboard
| (BYOK), so the application itself only needs the Cloudflare account id + API
| token. The gateway id and per-tenant `cf-aig-metadata` headers are injected
| by App\Providers\AiServiceProvider's global HTTP middleware.
|
| With no Cloudflare credentials set the `openai` driver falls back to calling
| OpenAI directly, so the SDK still works in local/dev.
|
| No closures here — config must stay var_export-able for `config:cache`.
*/

$cfAccount = env('CLOUDFLARE_ACCOUNT_ID');
$cfToken = env('CLOUDFLARE_API_TOKEN');
$cfApiBase = rtrim((string) env('CF_API_BASE', 'https://api.cloudflare.com/client/v4'), '/');
$cfGatewayId = env('CF_AIG_GATEWAY_ID', 'default');
$cfEnabled = (bool) ($cfAccount && $cfToken);
$cfInferenceUrl = $cfEnabled ? "{$cfApiBase}/accounts/{$cfAccount}/ai/v1" : null;

return [

    /*
    |--------------------------------------------------------------------------
    | Default AI Provider Names
    |--------------------------------------------------------------------------
    */

    // The chat transport. We route through the "openrouter" driver because it
    // speaks OpenAI **chat/completions** — the only shape Cloudflare's gateway
    // accepts. (The "openai" driver uses the newer /responses API, which the
    // gateway does not implement, so it 400s with "missing contents".)
    'default' => 'gateway',
    'default_for_images' => 'openai',
    'default_for_audio' => 'openai',
    'default_for_transcription' => 'openai',
    'default_for_embeddings' => 'openai',
    'default_for_reranking' => 'cohere',

    'caching' => [
        'embeddings' => [
            'cache' => false,
            'store' => env('CACHE_STORE', 'database'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | AI Providers
    |--------------------------------------------------------------------------
    |
    | The `openai` provider is the transport for ALL capabilities when a
    | Cloudflare gateway is configured: its URL points at the gateway REST
    | endpoint and its key is the Cloudflare API token. The actual upstream
    | provider is chosen by the "provider/model" string passed per request.
    |
    */

    'providers' => [
        // Cloudflare AI Gateway transport. Uses the OpenRouter driver (OpenAI
        // chat/completions wire format) pointed at the gateway, authenticated
        // with the Cloudflare token. The real upstream is the "provider/model"
        // string passed per request (e.g. google/gemini-2.5-flash-lite).
        'gateway' => [
            'driver' => 'openrouter',
            'key' => $cfEnabled ? $cfToken : env('OPENAI_API_KEY'),
            'url' => $cfEnabled ? $cfInferenceUrl : env('OPENAI_URL', 'https://api.openai.com/v1'),
        ],

        'openai' => [
            'driver' => 'openai',
            'key' => env('OPENAI_API_KEY'),
            'url' => env('OPENAI_URL', 'https://api.openai.com/v1'),
        ],

        // Kept for direct (non-gateway) use or local development.
        'anthropic' => [
            'driver' => 'anthropic',
            'key' => env('ANTHROPIC_API_KEY'),
            'url' => env('ANTHROPIC_URL', 'https://api.anthropic.com/v1'),
        ],

        'gemini' => [
            'driver' => 'gemini',
            'key' => env('GEMINI_API_KEY'),
            'url' => env('GEMINI_URL', 'https://generativelanguage.googleapis.com/v1beta/'),
        ],

        'cohere' => [
            'driver' => 'cohere',
            'key' => env('COHERE_API_KEY'),
        ],

        'ollama' => [
            'driver' => 'ollama',
            'key' => env('OLLAMA_API_KEY', ''),
            'url' => env('OLLAMA_URL', 'http://localhost:11434'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Cloudflare AI Gateway (app-specific)
    |--------------------------------------------------------------------------
    |
    | Used to inject gateway/metadata headers on inference requests and to read
    | spend back via the Gateway Logs API
    | (App\Services\Ai\CloudflareAiGatewayClient). The same token covers
    | inference and analytics unless a separate read token is provided.
    |
    */

    'gateway' => [
        'enabled' => $cfEnabled,
        'account_id' => $cfAccount,
        'gateway_id' => $cfGatewayId,
        'token' => $cfToken,
        'api_base' => $cfApiBase,
        'inference_url' => $cfInferenceUrl,
        // Token for the Logs API (AI Gateway Read). Falls back to the main token.
        'analytics_token' => env('CF_AI_GATEWAY_ANALYTICS_TOKEN', $cfToken),
        // Host whose outbound requests get the gateway/metadata headers.
        'host' => parse_url($cfApiBase, PHP_URL_HOST) ?: 'api.cloudflare.com',
    ],

    /*
    |--------------------------------------------------------------------------
    | Capability → default model
    |--------------------------------------------------------------------------
    |
    | Platform-wide default "provider/model" per capability. These are FALLBACK
    | defaults; the live values are managed from the back office and stored in
    | the global_settings table (keys `ai.model.<capability>`), read through
    | App\Support\Ai\AiModelConfig. The data-entry features use `text` + `vision`
    | (PDF-capable models recommended); the others are configured/health-checked
    | for future use.
    |
    */

    'capabilities' => [
        'text' => ['model' => env('AI_TEXT_MODEL', 'openai/gpt-4o-mini')],
        'vision' => ['model' => env('AI_VISION_MODEL', 'openai/gpt-4o-mini')],
        'image_generation' => ['model' => env('AI_IMAGE_MODEL', 'workers-ai/@cf/black-forest-labs/flux-1-schnell')],
        'audio_generation' => ['model' => env('AI_AUDIO_MODEL', 'openai/gpt-4o-mini-tts')],
        'audio_transcription' => ['model' => env('AI_TRANSCRIBE_MODEL', 'openai/whisper-1')],
    ],

    /*
    |--------------------------------------------------------------------------
    | Selectable models per capability (back-office dropdown)
    |--------------------------------------------------------------------------
    |
    | The "provider/model" choices offered in the back office for each
    | capability. Edit this list as providers ship new models — the currently
    | configured value is always shown even if it's no longer listed. The
    | test-before-save gate is the real guard that a chosen model works.
    |
    */

    'model_options' => [
        // NB: the unified REST endpoint uses the short author slug "google/…"
        // for Gemini (the provider-native "google-ai-studio/…" is rejected here).
        'text' => [
            'openai/gpt-4o-mini',
            'openai/gpt-4o',
            'anthropic/claude-haiku-4-5',
            'anthropic/claude-sonnet-4-5',
            'google/gemini-2.5-flash-lite',
            'google/gemini-2.5-flash',
            'google/gemini-2.5-pro',
            // Cloudflare Workers AI — runs on the included allowance (no BYOK/balance).
            '@cf/meta/llama-3.3-70b-instruct-fp8-fast',
            '@cf/moonshotai/kimi-k2.6',
        ],
        'vision' => [
            'openai/gpt-4o-mini',
            'openai/gpt-4o',
            'anthropic/claude-sonnet-4-5',
            'google/gemini-2.5-flash-lite',
            'google/gemini-2.5-flash',
            'google/gemini-2.5-pro',
            // Workers AI vision (reads images; PDFs would need rasterising first).
            '@cf/meta/llama-3.2-11b-vision-instruct',
        ],
        'image_generation' => [
            'openai/gpt-image-1',
            'openai/dall-e-3',
            'google/gemini-2.5-flash-image',
            'workers-ai/@cf/black-forest-labs/flux-1-schnell',
        ],
        'audio_generation' => [
            'openai/gpt-4o-mini-tts',
            'openai/tts-1',
            'elevenlabs/eleven_multilingual_v2',
        ],
        'audio_transcription' => [
            'openai/whisper-1',
            'openai/gpt-4o-transcribe',
            'elevenlabs/scribe_v1',
        ],
    ],

    // The provider name (above) used as the chat transport for all capabilities.
    'transport' => env('AI_TRANSPORT', 'gateway'),

    // Master kill-switch (back office can override via global_settings `ai.enabled`).
    'enabled' => env('AI_ENABLED', false),
];
