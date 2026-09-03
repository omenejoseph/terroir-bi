<?php

declare(strict_types=1);

namespace Tests\Feature\Web;

use App\Enums\TenantRole;
use App\Models\TranslationOverride;
use App\Services\Auth\ActiveTenantSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\Concerns\InteractsWithTenancy;
use Tests\TestCase;

/**
 * LanguageSwitcher.vue's server side. Session equivalent of the personal
 * locale precedence tier LocaleResolverTest covers in isolation.
 */
class LocaleSwitchTest extends TestCase
{
    use InteractsWithTenancy;
    use RefreshDatabase;

    public function test_switching_locale_persists_it_on_the_user_and_reflects_on_the_next_page(): void
    {
        $tenant = $this->createTenant(settings: ['default_locale' => 'hr']);
        $user = $this->createMember($tenant, [TenantRole::Admin]);

        $this->actingAs($user)
            ->withSession([ActiveTenantSession::KEY => $tenant->getKey()])
            ->from('/dashboard')
            ->patch('/locale', ['locale' => 'en'])
            ->assertRedirect('/dashboard')
            ->assertCookie('terroir_locale', 'en');

        $this->assertSame('en', $user->fresh()->locale);

        $this->actingAs($user->fresh())
            ->withSession([ActiveTenantSession::KEY => $tenant->getKey()])
            ->get('/dashboard')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('locale', 'en'));
    }

    public function test_a_guest_can_switch_locale_and_it_lands_in_a_cookie(): void
    {
        $this->from('/login')
            ->patch('/locale', ['locale' => 'en'])
            ->assertRedirect('/login')
            ->assertCookie('terroir_locale', 'en');
    }

    public function test_an_unsupported_locale_is_rejected(): void
    {
        $this->from('/login')
            ->patch('/locale', ['locale' => 'de'])
            ->assertSessionHasErrors('locale');
    }

    public function test_the_translations_prop_is_shared_and_reflects_a_db_override(): void
    {
        $tenant = $this->createTenant(settings: ['default_locale' => 'hr']);
        $user = $this->createMember($tenant, [TenantRole::Admin]);

        TranslationOverride::create(['locale' => 'hr', 'key' => 'Save', 'value' => 'Spremi']);

        $this->actingAs($user)
            ->withSession([ActiveTenantSession::KEY => $tenant->getKey()])
            ->get('/dashboard')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('translations.Save', 'Spremi'));
    }
}
