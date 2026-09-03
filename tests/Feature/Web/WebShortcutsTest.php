<?php

declare(strict_types=1);

namespace Tests\Feature\Web;

use App\Enums\TenantRole;
use App\Models\Tenant;
use App\Models\User;
use App\Models\UserNavVisit;
use App\Models\UserShortcut;
use App\Services\Auth\ActiveTenantSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\Concerns\InteractsWithTenancy;
use Tests\TestCase;

/**
 * Manage Shortcuts (Figma `143:4179`) over HTTP: saving pins, clearing visit
 * history, the visit tracker riding along on every tenant.web GET, and what
 * HandleInertiaRequests shares for it. The actions themselves are covered by
 * tests/Feature/Shortcuts/UserShortcutsActionsTest.
 */
class WebShortcutsTest extends TestCase
{
    use InteractsWithTenancy;
    use RefreshDatabase;

    /** @return array{0: Tenant, 1: User} */
    private function tenantAndMember(): array
    {
        $tenant = $this->createTenant();

        return [$tenant, $this->createMember($tenant, [TenantRole::Admin])];
    }

    /** @return array<string, string> */
    private function tenantSession(Tenant $tenant): array
    {
        return [ActiveTenantSession::KEY => $tenant->getKey()];
    }

    public function test_the_dashboard_shares_pinned_shortcuts_eagerly(): void
    {
        [$tenant, $admin] = $this->tenantAndMember();

        $this->actingAsTenant($tenant);
        UserShortcut::create(['user_id' => $admin->getKey(), 'nav_key' => 'orders', 'position' => 0]);
        $this->forgetTenant();

        $this->actingAs($admin)
            ->withSession($this->tenantSession($tenant))
            ->get('/dashboard')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('auth.shortcuts', ['orders']));
    }

    public function test_recent_nav_visits_is_absent_from_a_full_page_load(): void
    {
        [$tenant, $admin] = $this->tenantAndMember();

        $this->actingAs($admin)
            ->withSession($this->tenantSession($tenant))
            ->get('/dashboard')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->missing('recentNavVisits'));
    }

    public function test_recent_nav_visits_is_sent_when_a_partial_reload_asks_for_it(): void
    {
        [$tenant, $admin] = $this->tenantAndMember();

        $this->actingAsTenant($tenant);
        UserNavVisit::create(['user_id' => $admin->getKey(), 'nav_key' => 'orders', 'visited_at' => now()]);
        $this->forgetTenant();

        $response = $this->actingAs($admin)
            ->withSession($this->tenantSession($tenant))
            ->get('/dashboard', $this->inertiaPartial('Dashboard', 'recentNavVisits'));

        $response->assertOk();
        $this->assertSame(['orders'], $response->json('props.recentNavVisits'));
    }

    public function test_visiting_a_catalog_route_records_it_as_recent(): void
    {
        [$tenant, $admin] = $this->tenantAndMember();

        $this->actingAs($admin)
            ->withSession($this->tenantSession($tenant))
            ->get('/dashboard')
            ->assertOk();

        $this->assertDatabaseHas('user_nav_visits', [
            'user_id' => $admin->getKey(),
            'nav_key' => 'dashboard',
        ]);
    }

    public function test_revisiting_a_route_updates_the_timestamp_rather_than_duplicating(): void
    {
        [$tenant, $admin] = $this->tenantAndMember();

        $this->actingAs($admin)->withSession($this->tenantSession($tenant))->get('/dashboard')->assertOk();
        $this->actingAs($admin)->withSession($this->tenantSession($tenant))->get('/dashboard')->assertOk();

        $this->assertSame(
            1,
            UserNavVisit::query()->where('user_id', $admin->getKey())->where('nav_key', 'dashboard')->count(),
        );
    }

    public function test_updating_shortcuts_saves_the_pinned_list(): void
    {
        [$tenant, $admin] = $this->tenantAndMember();

        $this->actingAs($admin)
            ->withSession($this->tenantSession($tenant))
            ->patch('/shortcuts', ['keys' => ['orders', 'inventory']])
            ->assertRedirect();

        $this->assertDatabaseHas('user_shortcuts', ['user_id' => $admin->getKey(), 'nav_key' => 'orders']);
        $this->assertDatabaseHas('user_shortcuts', ['user_id' => $admin->getKey(), 'nav_key' => 'inventory']);
    }

    public function test_updating_shortcuts_with_an_empty_list_unpins_everything(): void
    {
        [$tenant, $admin] = $this->tenantAndMember();
        $this->actingAsTenant($tenant);
        UserShortcut::create(['user_id' => $admin->getKey(), 'nav_key' => 'orders', 'position' => 0]);
        $this->forgetTenant();

        $this->actingAs($admin)
            ->withSession($this->tenantSession($tenant))
            ->patch('/shortcuts', ['keys' => []])
            ->assertRedirect();

        $this->assertDatabaseMissing('user_shortcuts', ['user_id' => $admin->getKey()]);
    }

    public function test_clearing_recent_visits_removes_them(): void
    {
        [$tenant, $admin] = $this->tenantAndMember();
        $this->actingAsTenant($tenant);
        UserNavVisit::create(['user_id' => $admin->getKey(), 'nav_key' => 'orders', 'visited_at' => now()]);
        $this->forgetTenant();

        $this->actingAs($admin)
            ->withSession($this->tenantSession($tenant))
            ->delete('/shortcuts/recent')
            ->assertRedirect();

        $this->assertDatabaseMissing('user_nav_visits', ['user_id' => $admin->getKey()]);
    }

    public function test_a_member_cannot_pin_another_tenants_shortcuts(): void
    {
        $tenantA = $this->createTenant();
        $tenantB = $this->createTenant();
        $user = $this->createUser();
        $this->createMembershipFor($user, $tenantA);
        $this->createMembershipFor($user, $tenantB);

        $this->actingAs($user)
            ->withSession($this->tenantSession($tenantA))
            ->patch('/shortcuts', ['keys' => ['orders']])
            ->assertRedirect();

        $pinnedInB = UserShortcut::withoutTenant()
            ->where('tenant_id', $tenantB->getKey())
            ->where('user_id', $user->getKey())
            ->count();

        $this->assertSame(0, $pinnedInB);
    }
}
