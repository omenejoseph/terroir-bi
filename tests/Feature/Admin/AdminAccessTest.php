<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Actions\Tenancy\SetPlatformAdminAction;
use App\Enums\TenantRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithTenancy;
use Tests\TestCase;

/**
 * The platform.admin route group's auth boundary (bootstrap/app.php) — the
 * replacement for FilamentAccessTest now that /admin is the Inertia back
 * office (app/Http/Controllers/Web/Admin/**) rather than a Filament panel.
 */
class AdminAccessTest extends TestCase
{
    use InteractsWithTenancy;
    use RefreshDatabase;

    public function test_non_platform_admin_cannot_access_the_back_office(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get('/admin')->assertForbidden();
    }

    public function test_platform_admin_can_access_the_back_office(): void
    {
        $admin = User::factory()->create();
        app(SetPlatformAdminAction::class)->execute($admin, true);

        $this->actingAs($admin)->get('/admin')->assertSuccessful();
    }

    public function test_guests_are_redirected_to_login(): void
    {
        $this->get('/admin')->assertRedirect('/login');
    }

    public function test_a_platform_admin_with_no_organisation_lands_in_the_back_office(): void
    {
        // Nothing for them in the app: its tenant middleware would refuse them, so /admin it is.
        $admin = User::factory()->create(['password' => bcrypt('password123')]);
        app(SetPlatformAdminAction::class)->execute($admin, true);

        $this->post('/login', ['email' => $admin->email, 'password' => 'password123'])
            ->assertRedirect('/admin');
    }

    public function test_a_platform_admin_who_belongs_to_an_organisation_lands_in_the_app_first(): void
    {
        $tenant = $this->createTenant();
        $admin = $this->createMember($tenant, [TenantRole::Admin], ['password' => bcrypt('password123')]);
        app(SetPlatformAdminAction::class)->execute($admin, true);

        $this->post('/login', ['email' => $admin->email, 'password' => 'password123'])
            ->assertRedirect('/dashboard');
    }

    public function test_a_deep_link_still_wins_over_the_default_landing(): void
    {
        $tenant = $this->createTenant();
        $admin = $this->createMember($tenant, [TenantRole::Admin], ['password' => bcrypt('password123')]);
        app(SetPlatformAdminAction::class)->execute($admin, true);

        $this->get('/admin/plans')->assertRedirect('/login');
        $this->post('/login', ['email' => $admin->email, 'password' => 'password123'])
            ->assertRedirect('/admin/plans');
    }

    public function test_visiting_the_home_page_signed_in_follows_the_same_rule(): void
    {
        $tenant = $this->createTenant();
        $member = app(SetPlatformAdminAction::class)->execute($this->createMember($tenant, [TenantRole::Admin]), true);
        $opsOnly = app(SetPlatformAdminAction::class)->execute(User::factory()->create(), true);

        $this->actingAs($member)->get('/')->assertRedirect('/dashboard');
        $this->actingAs($opsOnly)->get('/')->assertRedirect('/admin');
    }
}
