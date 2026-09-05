<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\Admin;

use App\Enums\AiCapability;
use App\Http\Controllers\Controller;
use App\Services\Ai\AiClient;
use App\Support\Ai\AiModelConfig;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * Port of App\Filament\Pages\AiSettings: model configuration per capability,
 * each independently tested/enabled/disabled — a capability may only be
 * turned on once its live health check passes.
 */
class AiSettingsController extends Controller
{
    public function index(AiModelConfig $models): Response
    {
        return Inertia::render('Admin/AiSettings/Index', [
            'aiEnabled' => $models->enabled(),
            'gatewayConfigured' => (bool) config('ai.gateway.enabled'),
            'accountConfigured' => ! empty(config('ai.gateway.account_id')),
            'tokenConfigured' => ! empty(config('ai.gateway.token')),
            'requiredKeys' => $this->requiredKeys($models),
            'capabilities' => $this->capabilities($models),
            'modelOptions' => $this->modelOptions($models),
        ]);
    }

    public function configure(Request $request, AiModelConfig $models): RedirectResponse
    {
        $rules = [];
        foreach (AiCapability::cases() as $capability) {
            $rules[$capability->value] = ['required', 'string'];
        }

        $data = $request->validate($rules);

        foreach (AiCapability::cases() as $capability) {
            if (! empty($data[$capability->value])) {
                $models->setModel($capability, (string) $data[$capability->value]);
            }
        }

        return back()->with('success', __('Models saved.'));
    }

    public function testAll(AiClient $ai): RedirectResponse
    {
        $allOk = true;
        foreach (AiCapability::cases() as $capability) {
            $allOk = $ai->test($capability)->ok && $allOk;
        }

        return back()->with(
            $allOk ? 'success' : 'error',
            $allOk ? __('All capabilities passed.') : __('Some capabilities failed.'),
        );
    }

    public function testCapability(Request $request, AiClient $ai): RedirectResponse
    {
        $capability = $this->capabilityFromRoute($request);
        $result = $ai->test($capability);

        return back()->with(
            $result->ok ? 'success' : 'error',
            $result->ok
                ? __(':label passed.', ['label' => $capability->label()])
                : __(':label failed: :message', ['label' => $capability->label(), 'message' => (string) $result->message]),
        );
    }

    public function enableCapability(Request $request, AiClient $ai, AiModelConfig $models): RedirectResponse
    {
        $capability = $this->capabilityFromRoute($request);
        $result = $ai->test($capability);

        if (! $result->ok) {
            return back()->with('error', __('Cannot enable :label: :message', [
                'label' => $capability->label(),
                'message' => (string) $result->message,
            ]));
        }

        $models->setCapabilityEnabled($capability, true);

        return back()->with('success', __(':label enabled.', ['label' => $capability->label()]));
    }

    public function disableCapability(Request $request, AiModelConfig $models): RedirectResponse
    {
        $capability = $this->capabilityFromRoute($request);
        $models->setCapabilityEnabled($capability, false);

        return back()->with('success', __(':label disabled.', ['label' => $capability->label()]));
    }

    private function capabilityFromRoute(Request $request): AiCapability
    {
        $capability = AiCapability::tryFrom((string) $request->route('capability'));
        abort_if($capability === null, HttpResponse::HTTP_NOT_FOUND);

        return $capability;
    }

    /**
     * The keys required for the currently-selected models, grouped by
     * upstream provider — port of AiSettings::requiredKeys()/providerLabel().
     *
     * @return list<array{provider: string, label: string, byok: bool, capabilities: list<string>, models: list<string>}>
     */
    private function requiredKeys(AiModelConfig $models): array
    {
        $byProvider = [];

        foreach (AiCapability::cases() as $capability) {
            $model = $models->modelFor($capability);
            $provider = str_contains($model, '/') ? explode('/', $model, 2)[0] : $model;

            $byProvider[$provider] ??= ['capabilities' => [], 'models' => []];
            $byProvider[$provider]['capabilities'][] = $capability->label();
            $byProvider[$provider]['models'][] = $model;
        }

        $rows = [];
        foreach ($byProvider as $provider => $info) {
            $rows[] = [
                'provider' => $provider,
                'label' => $this->providerLabel($provider),
                'byok' => $provider !== 'workers-ai',
                'capabilities' => $info['capabilities'],
                'models' => array_values(array_unique($info['models'])),
            ];
        }

        return $rows;
    }

    private function providerLabel(string $provider): string
    {
        return match ($provider) {
            'openai' => 'OpenAI',
            'anthropic' => 'Anthropic',
            'google', 'google-ai-studio' => 'Google AI Studio (Gemini)',
            'google-vertex-ai' => 'Google Vertex AI (Gemini)',
            'workers-ai' => 'Cloudflare Workers AI',
            'elevenlabs' => 'ElevenLabs',
            'groq' => 'Groq',
            'mistral' => 'Mistral',
            'deepseek' => 'DeepSeek',
            'xai' => 'xAI',
            default => ucfirst($provider),
        };
    }

    /**
     * Suggested models per capability's datalist — the configured catalog
     * plus the current value. Hints only: an admin may type any exact
     * "provider/model" id the gateway exposes, then confirm with Test.
     *
     * @return array<string, list<string>>
     */
    private function modelOptions(AiModelConfig $models): array
    {
        $rows = [];
        foreach (AiCapability::cases() as $capability) {
            /** @var list<string> $options */
            $options = (array) config('ai.model_options.'.$capability->value, []);
            $current = $models->modelFor($capability);

            if ($current !== '' && ! in_array($current, $options, true)) {
                $options[] = $current;
            }

            $rows[$capability->value] = array_values(array_unique($options));
        }

        return $rows;
    }

    /**
     * @return array<string, array{label: string, model: string, enabled: bool}>
     */
    private function capabilities(AiModelConfig $models): array
    {
        $rows = [];
        foreach (AiCapability::cases() as $capability) {
            $rows[$capability->value] = [
                'label' => $capability->label(),
                'model' => $models->modelFor($capability),
                'enabled' => $models->capabilityEnabled($capability),
            ];
        }

        return $rows;
    }
}
