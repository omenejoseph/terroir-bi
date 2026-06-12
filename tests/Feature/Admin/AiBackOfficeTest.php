<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Actions\Tenancy\SetPlatformAdminAction;
use App\Enums\AiCapability;
use App\Enums\TenantStatus;
use App\Filament\Pages\AiSettings;
use App\Filament\Pages\AiSpend;
use App\Models\AiUsageLog;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Ai\AiModelConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Ai\AnonymousAgent;
use Livewire\Livewire;
use RuntimeException;
use Tests\TestCase;

class AiBackOfficeTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return app(SetPlatformAdminAction::class)->execute(User::factory()->create(), true);
    }

    /** @return array<string, mixed> */
    private function modelData(): array
    {
        $data = [];
        // Pick the first catalog option for each capability (the dropdown only
        // accepts listed values).
        foreach (AiCapability::cases() as $capability) {
            $options = (array) config('ai.model_options.'.$capability->value, []);
            $data[$capability->value] = $options[0];
        }

        return $data;
    }

    public function test_pages_render_for_platform_admin(): void
    {
        $this->actingAs($this->admin());

        Livewire::test(AiSpend::class)->assertOk();

        // The required-keys section reflects the providers of the selected models.
        app(AiModelConfig::class)->setModel(AiCapability::Vision, 'anthropic/claude-sonnet-4-5');

        Livewire::test(AiSettings::class)
            ->assertOk()
            ->assertSee('CLOUDFLARE_API_TOKEN')
            ->assertSee('OpenAI')      // default text model
            ->assertSee('Anthropic');  // selected vision model
    }

    public function test_configure_action_saves_models_only(): void
    {
        $this->actingAs($this->admin());

        Livewire::test(AiSettings::class)
            ->callAction('configure', $this->modelData())
            ->assertHasNoActionErrors();

        $models = app(AiModelConfig::class);
        $this->assertSame('openai/gpt-4o-mini', $models->modelFor(AiCapability::Text));
        // Saving models does NOT enable anything on its own.
        $this->assertFalse($models->enabled());
    }

    public function test_capability_is_enabled_only_when_its_check_passes(): void
    {
        AnonymousAgent::fake(['ok']); // the text health check passes
        $this->actingAs($this->admin());

        Livewire::test(AiSettings::class)->call('enableCapability', 'text');

        $models = app(AiModelConfig::class);
        $this->assertTrue($models->capabilityEnabled(AiCapability::Text));
        $this->assertFalse($models->capabilityEnabled(AiCapability::Vision)); // untouched
    }

    public function test_enable_is_refused_when_the_capability_check_fails(): void
    {
        AnonymousAgent::fake(fn () => throw new RuntimeException('no key'));
        $this->actingAs($this->admin());

        Livewire::test(AiSettings::class)
            ->call('enableCapability', 'text')
            ->assertSet('testResults.text.ok', false);

        $this->assertFalse(app(AiModelConfig::class)->capabilityEnabled(AiCapability::Text));
    }

    public function test_a_capability_can_be_disabled(): void
    {
        $this->actingAs($this->admin());
        app(AiModelConfig::class)->setCapabilityEnabled(AiCapability::Text, true);

        Livewire::test(AiSettings::class)->call('disableCapability', 'text');

        $this->assertFalse(app(AiModelConfig::class)->capabilityEnabled(AiCapability::Text));
    }

    public function test_provider_native_google_slug_is_corrected_for_the_unified_endpoint(): void
    {
        $models = app(AiModelConfig::class);
        // The provider-native slug isn't valid on the unified REST endpoint…
        $models->setModel(AiCapability::Vision, 'google-ai-studio/gemini-2.5-flash-lite');

        // …so it's corrected to the short author slug.
        $this->assertSame('google/gemini-2.5-flash-lite', $models->modelFor(AiCapability::Vision));
    }

    public function test_a_bare_model_id_is_prefixed_with_its_author(): void
    {
        $models = app(AiModelConfig::class);

        $cases = [
            'gemini-2.5-flash-lite' => 'google/gemini-2.5-flash-lite',
            'gpt-4o-mini' => 'openai/gpt-4o-mini',
            'claude-sonnet-4-5' => 'anthropic/claude-sonnet-4-5',
            'whisper-1' => 'openai/whisper-1',
            // Already author-qualified — left untouched.
            'anthropic/claude-haiku-4-5' => 'anthropic/claude-haiku-4-5',
        ];

        foreach ($cases as $bare => $expected) {
            $models->setModel(AiCapability::Text, $bare);
            $this->assertSame($expected, $models->modelFor(AiCapability::Text), "for input {$bare}");
        }
    }

    public function test_ai_spend_resolves_and_filters_per_tenant(): void
    {
        $alpha = Tenant::create(['name' => 'Alpha Co', 'slug' => 'alpha-spend', 'status' => TenantStatus::Active]);
        $beta = Tenant::create(['name' => 'Beta Co', 'slug' => 'beta-spend', 'status' => TenantStatus::Active]);
        AiUsageLog::create(['tenant_id' => $alpha->id, 'capability' => 'text', 'prompt_tokens' => 10, 'completion_tokens' => 5, 'ok' => true]);
        AiUsageLog::create(['tenant_id' => $beta->id, 'capability' => 'text', 'prompt_tokens' => 20, 'completion_tokens' => 8, 'ok' => true]);

        // All tenants → both rows, resolved to their names.
        $this->assertCount(2, (new AiSpend)->byTenant());

        // Filtered to one tenant → just that tenant's usage.
        $page = new AiSpend;
        $page->tenantId = $alpha->id;
        $filtered = $page->byTenant();

        $this->assertCount(1, $filtered);
        $this->assertSame('Alpha Co', $filtered[0]['tenant']);
        $this->assertSame(10, $filtered[0]['prompt_tokens']);
    }

    public function test_ai_spend_period_excludes_older_usage(): void
    {
        $alpha = Tenant::create(['name' => 'Alpha Co', 'slug' => 'alpha-period', 'status' => TenantStatus::Active]);
        AiUsageLog::create(['tenant_id' => $alpha->id, 'capability' => 'text', 'prompt_tokens' => 5, 'completion_tokens' => 1, 'ok' => true]);
        $old = AiUsageLog::create(['tenant_id' => $alpha->id, 'capability' => 'text', 'prompt_tokens' => 99, 'completion_tokens' => 1, 'ok' => true]);
        $old->created_at = now()->subDays(200);
        $old->save();

        $page = new AiSpend;
        $page->period = '7d';
        $totals = $page->totals();

        $this->assertSame(1, $totals['requests']); // the 200-day-old row is outside the window
        $this->assertSame(5, $totals['prompt_tokens']);
    }
}
