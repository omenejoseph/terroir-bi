<?php

declare(strict_types=1);

namespace Tests\Feature\Web;

use App\Enums\TenantRole;
use App\Models\Invitation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\Concerns\InteractsWithTenancy;
use Tests\TestCase;

/**
 * The public landing page for a Team invitation link
 * (Web\Auth\AcceptInvitationController) — Web\TeamInvitationController
 * generates the link, no email is sent. Establishes a cookie session, unlike
 * the API's own token-issuing accept endpoint (tests/Feature/Invitations
 * /InvitationTest covers that side); both share
 * App\Actions\Invitations\AcceptInvitationAction.
 */
class WebAcceptInvitationTest extends TestCase
{
    use InteractsWithTenancy;
    use RefreshDatabase;

    /**
     * @param  list<string>  $roles
     */
    private function invite(string $email, array $roles = ['TEAM']): string
    {
        $tenant = $this->createTenant();
        $admin = $this->createMember($tenant, [TenantRole::Admin]);

        $this->actingAs($admin)
            ->withSession(['active_tenant_id' => $tenant->getKey()])
            ->post('/settings/team/invitations', ['email' => $email, 'roles' => $roles]);

        $link = session('invite_link');
        $this->assertIsString($link);
        // /invitations/{token} — the token is everything after the last slash.
        $token = (string) str($link)->afterLast('/');
        $this->forgetTenant();

        // The accept routes are guest-only — actingAs() otherwise persists
        // the inviting admin's session onto every request the caller makes
        // next, and `guest` middleware would silently redirect those away
        // rather than reach the controller at all.
        auth()->guard('web')->logout();

        return $token;
    }

    public function test_show_renders_an_invalid_state_for_an_unknown_token(): void
    {
        $this->get('/invitations/does-not-exist')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Auth/AcceptInvitation')
                ->where('valid', false));
    }

    public function test_show_renders_the_invitation_details_for_a_valid_token(): void
    {
        $token = $this->invite('newhire@example.test', ['CELLAR']);

        $this->get("/invitations/{$token}")
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Auth/AcceptInvitation')
                ->where('valid', true)
                ->where('email', 'newhire@example.test')
                ->where('needsProfile', true));
    }

    public function test_new_email_accepting_creates_an_account_and_logs_them_in(): void
    {
        $token = $this->invite('newhire@example.test');

        $this->post('/invitations/accept', [
            'token' => $token,
            'first_name' => 'Nova',
            'last_name' => 'Hire',
            'password' => 'a-secure-password',
            'password_confirmation' => 'a-secure-password',
        ])
            ->assertRedirect('/dashboard');

        $this->assertAuthenticated();
        $this->assertDatabaseHas('users', ['email' => 'newhire@example.test']);
    }

    public function test_existing_account_must_confirm_their_own_password(): void
    {
        $existing = $this->createUser(['email' => 'existing@example.test']);
        $token = $this->invite('existing@example.test');

        // UserFactory's default password.
        $this->post('/invitations/accept', ['token' => $token, 'password' => 'password'])
            ->assertRedirect('/dashboard');

        $this->assertAuthenticatedAs($existing);
    }

    public function test_existing_account_with_wrong_password_is_rejected_and_not_logged_in(): void
    {
        $this->createUser(['email' => 'existing@example.test']);
        $token = $this->invite('existing@example.test');

        $this->post('/invitations/accept', ['token' => $token, 'password' => 'not-the-password'])
            ->assertSessionHasErrors('password');

        $this->assertGuest();
    }

    public function test_an_already_accepted_invitation_cannot_be_reused(): void
    {
        $token = $this->invite('newhire@example.test');

        $this->post('/invitations/accept', [
            'token' => $token,
            'first_name' => 'Nova',
            'last_name' => 'Hire',
            'password' => 'a-secure-password',
            'password_confirmation' => 'a-secure-password',
        ])->assertRedirect('/dashboard');

        auth()->logout();

        $this->post('/invitations/accept', [
            'token' => $token,
            'first_name' => 'Nova',
            'last_name' => 'Hire',
            'password' => 'a-secure-password',
            'password_confirmation' => 'a-secure-password',
        ])->assertSessionHasErrors('token');
    }

    public function test_expired_invitation_is_rejected(): void
    {
        $tenant = $this->createTenant();
        $this->createMember($tenant, [TenantRole::Admin]);

        $this->actingAsTenant($tenant);
        $invitation = Invitation::create([
            'email' => 'late@example.test',
            'roles' => collect([TenantRole::Team]),
            'token' => hash('sha256', 'a-plain-token'),
            'expires_at' => now()->subDay(),
        ]);
        $this->forgetTenant();

        $this->get('/invitations/a-plain-token')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('valid', false));

        $this->post('/invitations/accept', ['token' => 'a-plain-token', 'password' => 'password'])
            ->assertSessionHasErrors('token');

        $this->assertGuest();
        $fresh = Invitation::withoutTenant()->whereKey($invitation->getKey())->first();
        self::assertInstanceOf(Invitation::class, $fresh);
        $this->assertNull($fresh->accepted_at);
    }
}
