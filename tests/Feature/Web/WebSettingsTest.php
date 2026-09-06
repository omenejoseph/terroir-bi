<?php

declare(strict_types=1);

namespace Tests\Feature\Web;

use App\Enums\TenantRole;
use App\Services\Auth\ActiveTenantSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\Concerns\InteractsWithTenancy;
use Tests\TestCase;

/**
 * The Inertia organisation-settings page (nav's "System · Settings"). Reading
 * and writing both go through the same OrganizationSettingsData /
 * UpdateSettingsAction as the JSON API (tests/Feature/Settings/SettingsTest),
 * so these are about the page envelope and the `settings.manage` gate, not
 * the storage itself.
 */
class WebSettingsTest extends TestCase
{
    use InteractsWithTenancy;
    use RefreshDatabase;

    public function test_settings_page_renders_for_an_admin(): void
    {
        $tenant = $this->createTenant(['name' => 'Vinarija Test']);
        $admin = $this->createMember($tenant, [TenantRole::Admin]);

        $this->actingAs($admin)
            ->withSession([ActiveTenantSession::KEY => $tenant->getKey()])
            ->get('/settings')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Settings/Index')
                ->where('settings.name', 'Vinarija Test')
                ->where('settings.annual_revenue_target', null)
                ->has('localeOptions')
                ->has('timezoneOptions'));
    }

    /** No role grants settings.manage without ADMIN's wildcard — see App\Authorization\RoleCapabilities. */
    public function test_settings_page_is_closed_to_a_non_admin(): void
    {
        $tenant = $this->createTenant();
        $member = $this->createMember($tenant, [TenantRole::Team]);

        $this->actingAs($member)
            ->withSession([ActiveTenantSession::KEY => $tenant->getKey()])
            ->get('/settings')
            ->assertForbidden();
    }

    public function test_updating_settings_stores_the_revenue_target_and_cash_on_hand(): void
    {
        $tenant = $this->createTenant();
        $admin = $this->createMember($tenant, [TenantRole::Admin]);

        $this->actingAs($admin)
            ->withSession([ActiveTenantSession::KEY => $tenant->getKey()])
            ->patch('/settings', [
                'name' => 'Vinarija Test',
                'default_locale' => 'hr',
                'timezone' => 'Europe/Zagreb',
                'annual_revenue_target' => 1_200_000,
                'channel_revenue_targets' => ['wholesale' => 800_000],
                'cash_on_hand' => 300_000,
                'cash_on_hand_as_of' => '2026-02-01',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('tenant_settings', [
            'tenant_id' => $tenant->getKey(),
            'annual_revenue_target' => 1_200_000,
            'cash_on_hand' => 300_000,
        ]);
    }

    public function test_updating_settings_is_closed_to_a_non_admin(): void
    {
        $tenant = $this->createTenant();
        $member = $this->createMember($tenant, [TenantRole::Team]);

        $this->actingAs($member)
            ->withSession([ActiveTenantSession::KEY => $tenant->getKey()])
            ->patch('/settings', [
                'name' => 'Nope',
                'default_locale' => 'hr',
                'timezone' => 'Europe/Zagreb',
            ])
            ->assertForbidden();
    }
}
