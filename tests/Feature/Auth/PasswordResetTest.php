<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Actions\Tenancy\SetPlatformAdminAction;
use App\Enums\TenantRole;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\InteractsWithTenancy;
use Tests\TestCase;

/**
 * The real email flow, built from scratch (nothing existed before this):
 * admin triggers Password::sendResetLink -> the framework's default
 * ResetPassword notification -> its default resetUrl() resolves to the
 * `password.reset` route registered in routes/web.php -> PasswordResetController.
 */
class PasswordResetTest extends TestCase
{
    use InteractsWithTenancy;
    use RefreshDatabase;

    public function test_admin_triggering_a_reset_creates_a_token_and_sends_the_notification(): void
    {
        Notification::fake();

        $tenant = $this->createTenant();
        $user = $this->createMember($tenant, [TenantRole::Admin]);
        $admin = $this->createUser();
        app(SetPlatformAdminAction::class)->execute($admin, true);

        $this->actingAs($admin)
            ->post("/admin/users/{$user->getKey()}/send-password-reset")
            ->assertRedirect();

        $this->assertDatabaseHas('password_reset_tokens', ['email' => $user->email]);
        Notification::assertSentTo($user, ResetPassword::class);
    }

    public function test_completing_the_reset_with_a_valid_token_changes_the_password(): void
    {
        $tenant = $this->createTenant();
        $user = $this->createMember($tenant, [TenantRole::Admin]);

        $token = app('auth.password.broker')->createToken($user);

        $this->get("/reset-password/{$token}?email=".urlencode($user->email))->assertOk();

        $this->post('/reset-password', [
            'token' => $token,
            'email' => $user->email,
            'password' => 'new-password-123',
            'password_confirmation' => 'new-password-123',
        ])->assertRedirect('/login');

        // The old password no longer works…
        $this->post('/login', ['email' => $user->email, 'password' => 'password'])
            ->assertSessionHasErrors('email');
        $this->assertGuest();

        // …the new one does.
        $this->post('/login', ['email' => $user->email, 'password' => 'new-password-123'])
            ->assertRedirect('/dashboard');
    }

    public function test_an_invalid_token_is_rejected(): void
    {
        $tenant = $this->createTenant();
        $user = $this->createMember($tenant, [TenantRole::Admin]);

        $this->post('/reset-password', [
            'token' => 'not-a-real-token',
            'email' => $user->email,
            'password' => 'new-password-123',
            'password_confirmation' => 'new-password-123',
        ])->assertSessionHasErrors('email');

        // The old password is untouched.
        $this->post('/login', ['email' => $user->email, 'password' => 'password'])
            ->assertRedirect('/dashboard');
    }
}
