<?php

declare(strict_types=1);

namespace Tests\Feature\Web;

use App\Enums\TenantRole;
use App\Models\AuditLog;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Auth\ActiveTenantSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\Response;
use Tests\Concerns\InteractsWithTenancy;
use Tests\TestCase;

/** "My profile": every member can change their own name and password, and nobody else's. */
class WebProfileTest extends TestCase
{
    use InteractsWithTenancy;
    use RefreshDatabase;

    private const OLD = 'old-password-123';

    private const NEW = 'brand-new-password-456';

    private Tenant $tenant;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = $this->createTenant();
        $this->user = $this->createMember($this->tenant, [TenantRole::Orders], [
            'first_name' => 'Karla', 'middle_name' => null, 'last_name' => 'Bibić', 'email' => 'karla@example.test', 'password' => self::OLD,
        ]);
    }

    private function as(User $user): static
    {
        return $this->actingAs($user)->withSession([ActiveTenantSession::KEY => $this->tenant->getKey()]);
    }

    /**
     * @param  array<string, mixed>  $o
     * @return TestResponse<Response>
     */
    private function password(array $o = []): TestResponse
    {
        return $this->as($this->user)->put('/profile/password', $o + [
            'current_password' => self::OLD, 'password' => self::NEW, 'password_confirmation' => self::NEW,
        ]);
    }

    public function test_guests_are_sent_to_login(): void
    {
        $this->get('/profile')->assertRedirect('/login');
        $this->patch('/profile', ['first_name' => 'X', 'last_name' => 'Y'])->assertRedirect('/login');
        $this->put('/profile/password', [])->assertRedirect('/login');
    }

    public function test_the_page_shows_your_own_details_and_never_a_password(): void
    {
        $this->as($this->user)->get('/profile')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Profile/Edit')
            ->where('profile.first_name', 'Karla')
            ->where('profile.last_name', 'Bibić')
            ->where('profile.email', 'karla@example.test')
            ->where('readOnly', false)
            ->missing('profile.password'));
    }

    public function test_every_role_can_open_it_even_one_with_no_other_access(): void
    {
        foreach (TenantRole::cases() as $role) {
            $member = $this->createMember($this->tenant, [$role]);

            $this->as($member)->get('/profile')->assertOk();
        }
    }

    public function test_names_can_be_changed_and_are_trimmed(): void
    {
        $this->as($this->user)->patch('/profile', ['first_name' => '  Karla ', 'middle_name' => ' Ana ', 'last_name' => 'Bibić-Horvat'])
            ->assertRedirect();

        $this->user->refresh();
        $this->assertSame(['Karla', 'Ana', 'Bibić-Horvat'], [$this->user->first_name, $this->user->middle_name, $this->user->last_name]);
        $this->assertSame('Karla Ana Bibić-Horvat', $this->user->fullName());
    }

    public function test_a_blank_middle_name_clears_it(): void
    {
        $this->user->forceFill(['middle_name' => 'Ana'])->save();

        $this->as($this->user)->patch('/profile', ['first_name' => 'Karla', 'middle_name' => '   ', 'last_name' => 'Bibić'])->assertRedirect();

        $this->assertNull($this->user->refresh()->middle_name);
    }

    public function test_first_and_last_name_are_required(): void
    {
        $this->as($this->user)->patch('/profile', ['first_name' => '', 'last_name' => ''])
            ->assertSessionHasErrors(['first_name', 'last_name']);

        $this->assertSame('Karla', $this->user->refresh()->first_name);
    }

    public function test_email_cannot_be_changed_here(): void
    {
        $this->as($this->user)->patch('/profile', ['first_name' => 'Karla', 'last_name' => 'Bibić', 'email' => 'someone-else@example.test'])->assertRedirect();

        $this->assertSame('karla@example.test', $this->user->refresh()->email);
    }

    public function test_only_real_changes_are_audited(): void
    {
        $this->as($this->user)->patch('/profile', ['first_name' => 'Karla', 'last_name' => 'Bibić'])->assertRedirect();
        $this->assertSame(0, AuditLog::query()->where('action', 'user.profile_updated')->count());

        $this->as($this->user)->patch('/profile', ['first_name' => 'Karlo', 'last_name' => 'Bibić'])->assertRedirect();
        $log = AuditLog::query()->where('action', 'user.profile_updated')->sole();
        $this->assertSame($this->user->getKey(), $log->actor_id);
        $this->assertSame(['first_name'], ($log->metadata ?? [])['changed'] ?? null);
    }

    public function test_the_password_can_be_changed_and_you_stay_signed_in(): void
    {
        $this->password()->assertRedirect()->assertSessionHasNoErrors();

        $this->user->refresh();
        $this->assertTrue(Hash::check(self::NEW, $this->user->password));
        $this->assertFalse(Hash::check(self::OLD, $this->user->password));
        $this->assertAuthenticatedAs($this->user);
        $this->get('/profile')->assertOk();
    }

    public function test_a_password_change_is_audited_without_recording_the_password(): void
    {
        $this->password()->assertRedirect();

        $log = AuditLog::query()->where('action', 'user.password_changed')->sole();
        $this->assertSame([$this->user->getKey(), $this->tenant->getKey()], [$log->actor_id, $log->tenant_id]);
        $this->assertNull($log->metadata);
        $this->assertStringNotContainsString(self::NEW, (string) json_encode($log->toArray()));
    }

    public function test_changing_the_password_revokes_api_tokens_but_only_your_own(): void
    {
        $other = $this->createMember($this->tenant, [TenantRole::Team]);
        $this->user->createToken('phone');
        $other->createToken('phone');
        $otherHash = $other->password;

        $this->password()->assertRedirect();

        $this->assertSame(0, $this->user->tokens()->toBase()->count());
        $this->assertSame(1, $other->tokens()->toBase()->count());
        $this->assertSame($otherHash, $other->refresh()->password, 'the other account is untouched');
    }

    /** @return array<string, array{array<string, string>, string}> */
    public static function badPasswords(): array
    {
        return [
            'wrong current password' => [['current_password' => 'not-my-password'], 'current_password'],
            'missing current password' => [['current_password' => ''], 'current_password'],
            'confirmation does not match' => [['password_confirmation' => 'something-else-999'], 'password'],
            'too short' => [['password' => 'short1', 'password_confirmation' => 'short1'], 'password'],
            'same as the current one' => [['password' => self::OLD, 'password_confirmation' => self::OLD], 'password'],
            'blank new password' => [['password' => '', 'password_confirmation' => ''], 'password'],
        ];
    }

    /**
     * @param  array<string, string>  $override
     *
     * @dataProvider badPasswords
     */
    #[DataProvider('badPasswords')]
    public function test_a_bad_password_change_is_refused_and_changes_nothing(array $override, string $errorOn): void
    {
        $this->user->createToken('phone');

        $this->password($override)->assertSessionHasErrors($errorOn);

        $this->assertTrue(Hash::check(self::OLD, $this->user->refresh()->password));
        $this->assertSame(1, $this->user->tokens()->toBase()->count(), 'a refused change must not revoke tokens');
        $this->assertSame(0, AuditLog::query()->where('action', 'user.password_changed')->count());
    }

    public function test_you_can_only_change_your_own_password(): void
    {
        $other = $this->createMember($this->tenant, [TenantRole::Team], ['password' => 'their-password-1']);

        // There is no way to name another user: the route takes no id, and a smuggled one is ignored.
        $this->as($this->user)->put('/profile/password', [
            'current_password' => self::OLD, 'password' => self::NEW, 'password_confirmation' => self::NEW, 'user_id' => $other->getKey(), 'id' => $other->getKey(),
        ])->assertRedirect();

        $this->assertTrue(Hash::check('their-password-1', $other->refresh()->password));
        $this->assertTrue(Hash::check(self::NEW, $this->user->refresh()->password));
    }

    public function test_the_new_password_works_for_signing_in_again(): void
    {
        $this->password()->assertRedirect();
        $this->post('/logout');

        $this->post('/login', ['email' => 'karla@example.test', 'password' => self::OLD])->assertSessionHasErrors();
        $this->post('/login', ['email' => 'karla@example.test', 'password' => self::NEW])->assertRedirect();
        $this->assertAuthenticatedAs($this->user);
    }

    public function test_an_impersonating_admin_can_look_but_not_change_the_persons_account(): void
    {
        $admin = $this->createMember($this->tenant, [TenantRole::Admin]);
        $membership = $this->user->membershipFor($this->tenant) ?? throw new \LogicException('no membership');

        $this->as($admin)->post("/settings/team/{$membership->getKey()}/impersonate")->assertRedirect();
        $this->assertAuthenticatedAs($this->user);

        $this->get('/profile')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->where('readOnly', true));
        $this->put('/profile/password', ['current_password' => self::OLD, 'password' => self::NEW, 'password_confirmation' => self::NEW])->assertForbidden();
        $this->patch('/profile', ['first_name' => 'Hijacked', 'last_name' => 'Name'])->assertForbidden();

        $this->user->refresh();
        $this->assertTrue(Hash::check(self::OLD, $this->user->password));
        $this->assertSame('Karla', $this->user->first_name);
    }
}
