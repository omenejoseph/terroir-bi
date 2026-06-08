<?php

declare(strict_types=1);

namespace Tests\Feature\Localization;

use App\Models\TranslationOverride;
use App\Services\Localization\TranslationServiceInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithTenancy;
use Tests\TestCase;

class TranslationOverrideTest extends TestCase
{
    use InteractsWithTenancy;
    use RefreshDatabase;

    private function service(): TranslationServiceInterface
    {
        return app(TranslationServiceInterface::class);
    }

    public function test_db_override_takes_precedence_over_the_key(): void
    {
        $this->actingAsTenant($this->createTenant());

        TranslationOverride::create(['locale' => 'hr', 'key' => 'orders.title', 'value' => 'Narudžbe']);

        $this->assertSame('Narudžbe', $this->service()->get('orders.title', [], 'hr'));
        // No override -> the key itself is returned.
        $this->assertSame('orders.missing', $this->service()->get('orders.missing', [], 'hr'));
    }

    public function test_overrides_are_isolated_per_tenant(): void
    {
        $a = $this->createTenant();
        $b = $this->createTenant();

        $this->actingAsTenant($a);
        TranslationOverride::create(['locale' => 'hr', 'key' => 'k', 'value' => 'A value']);

        $this->actingAsTenant($a);
        $this->assertSame('A value', $this->service()->get('k', [], 'hr'));

        $this->actingAsTenant($b);
        $this->assertSame('k', $this->service()->get('k', [], 'hr'));
    }

    public function test_the_translation_loader_merges_overrides_for_json_translations(): void
    {
        $this->actingAsTenant($this->createTenant());

        TranslationOverride::create(['locale' => 'hr', 'key' => 'Save', 'value' => 'Spremi']);

        $merged = app('translator')->getLoader()->load('hr', '*', '*');

        $this->assertSame('Spremi', $merged['Save']);
    }

    public function test_cache_is_busted_when_an_override_changes(): void
    {
        $this->actingAsTenant($this->createTenant());

        // Prime the cache with no overrides.
        $this->assertSame([], $this->service()->overrides('hr'));

        TranslationOverride::create(['locale' => 'hr', 'key' => 'k', 'value' => 'v']);

        // Observer should have flushed the cache.
        $this->assertSame(['k' => 'v'], $this->service()->overrides('hr'));
    }

    public function test_replacements_are_applied_to_overrides(): void
    {
        $this->actingAsTenant($this->createTenant());

        TranslationOverride::create(['locale' => 'hr', 'key' => 'welcome', 'value' => 'Bok :name']);

        $this->assertSame('Bok Ana', $this->service()->get('welcome', ['name' => 'Ana'], 'hr'));
    }
}
