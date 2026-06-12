<?php

namespace App\Filament\Pages;

use App\Enums\AiCapability;
use App\Services\Ai\AiClient;
use App\Services\Ai\CapabilityTestResult;
use App\Support\Ai\AiModelConfig;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

/**
 * Back-office AI configuration: pick the model per capability, then test and
 * enable each capability independently. A capability can only be turned ON once
 * its live health check passes — mirroring the Stripe page's "test before
 * trust" posture, but per capability.
 */
class AiSettings extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSparkles;

    protected static string|UnitEnum|null $navigationGroup = 'AI';

    protected static ?int $navigationSort = 10;

    protected string $view = 'filament.pages.ai-settings';

    /**
     * Results of the last "Test capabilities" run, keyed by capability value.
     *
     * @var array<string, array{ok: bool, model: string, message: string|null}>
     */
    public array $testResults = [];

    public function getTitle(): string
    {
        return 'AI';
    }

    public function aiEnabled(): bool
    {
        return app(AiModelConfig::class)->enabled();
    }

    public function gatewayConfigured(): bool
    {
        return (bool) config('ai.gateway.enabled');
    }

    public function accountConfigured(): bool
    {
        return ! empty(config('ai.gateway.account_id'));
    }

    public function tokenConfigured(): bool
    {
        return ! empty(config('ai.gateway.token'));
    }

    /**
     * The keys required for the currently-selected models, grouped by upstream
     * provider. Provider keys are stored in the Cloudflare dashboard (BYOK), not
     * in this app — so we can list what's needed and where, but the live test is
     * what actually confirms a key reaches the provider.
     *
     * @return list<array{provider: string, label: string, byok: bool, capabilities: list<string>, models: list<string>}>
     */
    public function requiredKeys(): array
    {
        $models = app(AiModelConfig::class);
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
                // Cloudflare Workers AI runs on Cloudflare itself — no external key.
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
     * Capability → current model, for the view.
     *
     * @return array<string, array{label: string, model: string}>
     */
    public function capabilities(): array
    {
        $models = app(AiModelConfig::class);
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

    /**
     * Suggested models for a capability's datalist: the configured catalog plus
     * the current value. These are hints — the admin can type any exact
     * "provider/model" id the gateway exposes (model ids vary by provider and
     * change often), then confirm it with the Test button.
     *
     * @return list<string>
     */
    private function modelSuggestions(AiCapability $capability): array
    {
        /** @var list<string> $options */
        $options = (array) config('ai.model_options.'.$capability->value, []);
        $current = app(AiModelConfig::class)->modelFor($capability);

        if ($current !== '' && ! in_array($current, $options, true)) {
            $options[] = $current;
        }

        return array_values(array_unique($options));
    }

    /**
     * @return array<int, Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            $this->configureAction(),
            $this->testAction(),
        ];
    }

    private function configureAction(): Action
    {
        $models = app(AiModelConfig::class);

        $fields = [];
        foreach (AiCapability::cases() as $capability) {
            $fields[] = TextInput::make($capability->value)
                ->label($capability->label())
                ->datalist($this->modelSuggestions($capability))
                ->helperText('Pick a suggestion or paste a model id. A bare id from the provider docs (e.g. gemini-2.5-flash-lite, gpt-4o-mini, claude-…) is auto-prefixed with its gateway author (google/openai/anthropic); then use Test to confirm it works.')
                ->required();
        }

        return Action::make('configure')
            ->label('Configure models')
            ->icon(Heroicon::OutlinedCog6Tooth)
            ->fillForm(fn (): array => $models->all())
            ->form($fields)
            ->action(function (array $data) use ($models): void {
                foreach (AiCapability::cases() as $capability) {
                    if (! empty($data[$capability->value])) {
                        $models->setModel($capability, (string) $data[$capability->value]);
                    }
                }

                Notification::make()->title('Models saved')->success()->send();
            });
    }

    private function testAction(): Action
    {
        return Action::make('testAll')
            ->label('Test all')
            ->icon(Heroicon::OutlinedBolt)
            ->action(function (): void {
                $allOk = true;
                foreach (AiCapability::cases() as $capability) {
                    $allOk = $this->runTest($capability)->ok && $allOk;
                }

                Notification::make()
                    ->title($allOk ? 'All capabilities passed' : 'Some capabilities failed')
                    ->{$allOk ? 'success' : 'warning'}()
                    ->send();
            });
    }

    // --- Per-capability actions (called from the table via wire:click) --------

    public function testCapability(string $capability): void
    {
        $result = $this->runTest(AiCapability::from($capability));

        Notification::make()
            ->title($result->ok ? $result->capability->label().' passed' : $result->capability->label().' failed')
            ->body($result->message)
            ->{$result->ok ? 'success' : 'danger'}()
            ->send();
    }

    public function enableCapability(string $capability): void
    {
        $cap = AiCapability::from($capability);
        $result = $this->runTest($cap);

        if (! $result->ok) {
            Notification::make()
                ->title('Cannot enable '.$cap->label())
                ->body('The capability check failed: '.$result->message)
                ->danger()
                ->send();

            return;
        }

        app(AiModelConfig::class)->setCapabilityEnabled($cap, true);

        Notification::make()->title($cap->label().' enabled')->success()->send();
    }

    public function disableCapability(string $capability): void
    {
        $cap = AiCapability::from($capability);
        app(AiModelConfig::class)->setCapabilityEnabled($cap, false);

        Notification::make()->title($cap->label().' disabled')->send();
    }

    private function runTest(AiCapability $capability): CapabilityTestResult
    {
        $result = app(AiClient::class)->test($capability);

        $this->testResults[$capability->value] = [
            'ok' => $result->ok,
            'model' => $result->model,
            'message' => $result->message,
        ];

        return $result;
    }
}
