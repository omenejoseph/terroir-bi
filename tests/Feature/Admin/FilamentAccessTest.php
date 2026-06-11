<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Actions\Tenancy\SetPlatformAdminAction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FilamentAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_non_platform_admin_cannot_access_the_panel(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get('/admin')->assertForbidden();
    }

    public function test_platform_admin_can_access_the_panel(): void
    {
        $admin = User::factory()->create();
        app(SetPlatformAdminAction::class)->execute($admin, true);

        $this->actingAs($admin)->get('/admin')->assertSuccessful();
    }

    public function test_guests_are_redirected_to_login(): void
    {
        $this->get('/admin')->assertRedirect();
    }
}
