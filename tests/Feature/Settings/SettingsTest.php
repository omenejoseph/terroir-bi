<?php

declare(strict_types=1);

namespace Tests\Feature\Settings;

use App\Enums\TenantRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\InteractsWithTenancy;
use Tests\TestCase;

class SettingsTest extends TestCase
{
    use InteractsWithTenancy;
    use RefreshDatabase;

    public function test_any_member_can_read_settings(): void
    {
        $tenant = $this->createTenant(['name' => 'Vinarija Test'], [
            'default_currency' => 'EUR',
            'default_locale' => 'hr',
            'timezone' => 'Europe/Zagreb',
        ]);
        $member = $this->createMember($tenant, [TenantRole::Cellar]);

        Sanctum::actingAs($member);

        $this->getJson('/api/v1/settings', $this->tenantHeader($tenant))
            ->assertOk()
            ->assertJsonPath('data.name', 'Vinarija Test')
            ->assertJsonPath('data.default_currency', 'EUR')
            ->assertJsonPath('data.default_locale', 'hr')
            ->assertJsonPath('data.timezone', 'Europe/Zagreb');
    }

    public function test_admin_can_update_settings(): void
    {
        $tenant = $this->createTenant(['name' => 'Old Name']);
        $admin = $this->createMember($tenant, [TenantRole::Admin]);

        Sanctum::actingAs($admin);

        $this->patchJson('/api/v1/settings', [
            'name' => 'New Name',
            'default_locale' => 'en',
            'timezone' => 'America/New_York',
            'company_oib' => '12345678901',
        ], $this->tenantHeader($tenant))
            ->assertOk()
            ->assertJsonPath('data.name', 'New Name')
            ->assertJsonPath('data.default_locale', 'en')
            ->assertJsonPath('data.timezone', 'America/New_York')
            ->assertJsonPath('data.company_oib', '12345678901');

        $this->assertDatabaseHas('tenants', [
            'id' => $tenant->getKey(),
            'name' => 'New Name',
            'default_locale' => 'en',
        ]);
        $this->assertDatabaseHas('tenant_settings', [
            'tenant_id' => $tenant->getKey(),
            'default_locale' => 'en',
            'timezone' => 'America/New_York',
            'company_oib' => '12345678901',
        ]);
    }

    public function test_currency_is_read_only_and_cannot_be_changed(): void
    {
        $tenant = $this->createTenant([], ['default_currency' => 'EUR']);
        $admin = $this->createMember($tenant, [TenantRole::Admin]);

        Sanctum::actingAs($admin);

        $this->patchJson('/api/v1/settings', [
            'name' => 'Whatever',
            'default_locale' => 'hr',
            'timezone' => 'Europe/Zagreb',
            'default_currency' => 'USD', // should be ignored
        ], $this->tenantHeader($tenant))
            ->assertOk()
            ->assertJsonPath('data.default_currency', 'EUR');

        $this->assertDatabaseHas('tenant_settings', [
            'tenant_id' => $tenant->getKey(),
            'default_currency' => 'EUR',
        ]);
    }

    public function test_validation_rejects_unknown_locale_and_timezone(): void
    {
        $tenant = $this->createTenant();
        $admin = $this->createMember($tenant, [TenantRole::Admin]);

        Sanctum::actingAs($admin);

        $this->patchJson('/api/v1/settings', [
            'name' => 'X',
            'default_locale' => 'de', // not in supported_locales
            'timezone' => 'Mars/Olympus', // not a real tz
        ], $this->tenantHeader($tenant))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['default_locale', 'timezone']);
    }

    public function test_non_admin_cannot_update_settings(): void
    {
        $tenant = $this->createTenant();
        $member = $this->createMember($tenant, [TenantRole::Team]);

        Sanctum::actingAs($member);

        $this->patchJson('/api/v1/settings', [
            'name' => 'Nope',
            'default_locale' => 'en',
            'timezone' => 'Europe/Zagreb',
        ], $this->tenantHeader($tenant))->assertForbidden();
    }

    public function test_login_session_includes_settings(): void
    {
        $tenant = $this->createTenant(['name' => 'Session Co'], ['timezone' => 'Europe/Zagreb']);
        $admin = $this->createMember($tenant, [TenantRole::Admin]);

        Sanctum::actingAs($admin);

        $this->getJson('/api/v1/auth/me', $this->tenantHeader($tenant))
            ->assertOk()
            ->assertJsonPath('data.settings.name', 'Session Co')
            ->assertJsonPath('data.settings.timezone', 'Europe/Zagreb')
            ->assertJsonPath('data.settings.default_currency', 'EUR');
    }
}
