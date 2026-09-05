<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Actions\Tenancy\SetPlatformAdminAction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The platform.admin route group's auth boundary (bootstrap/app.php) — the
 * replacement for FilamentAccessTest now that /admin is the Inertia back
 * office (app/Http/Controllers/Web/Admin/**) rather than a Filament panel.
 */
class AdminAccessTest extends TestCase
{
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

    public function test_login_sends_a_platform_admin_straight_to_the_back_office(): void
    {
        $admin = User::factory()->create(['password' => bcrypt('password123')]);
        app(SetPlatformAdminAction::class)->execute($admin, true);

        $this->post('/login', ['email' => $admin->email, 'password' => 'password123'])
            ->assertRedirect('/admin');
    }
}
