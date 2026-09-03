<?php

declare(strict_types=1);

namespace Tests\Feature\Shortcuts;

use App\Actions\Shortcuts\ClearRecentVisitsAction;
use App\Actions\Shortcuts\RecordNavVisitAction;
use App\Actions\Shortcuts\SetPinnedShortcutsAction;
use App\Models\UserNavVisit;
use App\Models\UserShortcut;
use App\Queries\UserShortcutsQuery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithTenancy;
use Tests\TestCase;

/**
 * The Manage Shortcuts backend (Figma `143:4179`) below the HTTP layer:
 * pinning, unpinning, recording a visit, and clearing history. The routes
 * themselves are covered by tests/Feature/Web/WebShortcutsTest.
 */
class UserShortcutsActionsTest extends TestCase
{
    use InteractsWithTenancy;
    use RefreshDatabase;

    public function test_setting_pinned_shortcuts_saves_them_in_order(): void
    {
        $tenant = $this->createTenant();
        $user = $this->createMember($tenant);
        $this->actingAsTenant($tenant);

        app(SetPinnedShortcutsAction::class)->execute($user, ['orders', 'inventory', 'customers']);

        $this->assertSame(
            ['orders', 'inventory', 'customers'],
            app(UserShortcutsQuery::class)->pinned($user),
        );
    }

    public function test_setting_pinned_shortcuts_replaces_the_previous_set(): void
    {
        $tenant = $this->createTenant();
        $user = $this->createMember($tenant);
        $this->actingAsTenant($tenant);

        $action = app(SetPinnedShortcutsAction::class);
        $action->execute($user, ['orders', 'customers']);
        $action->execute($user, ['inventory']);

        $this->assertSame(['inventory'], app(UserShortcutsQuery::class)->pinned($user));
    }

    /** A stale pin from a removed nav item must not block saving the rest of the list. */
    public function test_unknown_keys_are_dropped_not_rejected(): void
    {
        $tenant = $this->createTenant();
        $user = $this->createMember($tenant);
        $this->actingAsTenant($tenant);

        app(SetPinnedShortcutsAction::class)->execute($user, ['orders', 'not-a-real-key']);

        $this->assertSame(['orders'], app(UserShortcutsQuery::class)->pinned($user));
    }

    public function test_pins_are_scoped_per_tenant(): void
    {
        $tenantA = $this->createTenant();
        $tenantB = $this->createTenant();
        $user = $this->createUser();
        $this->createMembershipFor($user, $tenantA);
        $this->createMembershipFor($user, $tenantB);

        $this->actingAsTenant($tenantA);
        app(SetPinnedShortcutsAction::class)->execute($user, ['orders']);

        $this->actingAsTenant($tenantB);
        $this->assertSame([], app(UserShortcutsQuery::class)->pinned($user));
    }

    public function test_recording_a_visit_upserts_rather_than_duplicating(): void
    {
        $tenant = $this->createTenant();
        $user = $this->createMember($tenant);
        $this->actingAsTenant($tenant);

        $action = app(RecordNavVisitAction::class);
        $action->execute($user, 'dashboard');
        $action->execute($user, 'dashboard');

        $this->assertSame(1, UserNavVisit::query()->where('nav_key', 'dashboard')->count());
    }

    public function test_recent_visits_are_ordered_newest_first_and_capped(): void
    {
        $tenant = $this->createTenant();
        $user = $this->createMember($tenant);
        $this->actingAsTenant($tenant);

        $action = app(RecordNavVisitAction::class);
        foreach (['dashboard', 'orders', 'customers', 'work-orders', 'inventory', 'cash-system'] as $key) {
            $action->execute($user, $key);
        }

        $recent = app(UserShortcutsQuery::class)->recent($user, limit: 5);

        $this->assertSame(['cash-system', 'inventory', 'work-orders', 'customers', 'orders'], $recent);
    }

    public function test_clearing_recent_visits_removes_them_for_that_user(): void
    {
        $tenant = $this->createTenant();
        $user = $this->createMember($tenant);
        $other = $this->createMember($tenant);
        $this->actingAsTenant($tenant);

        app(RecordNavVisitAction::class)->execute($user, 'dashboard');
        app(RecordNavVisitAction::class)->execute($other, 'orders');

        app(ClearRecentVisitsAction::class)->execute($user);

        $this->assertSame([], app(UserShortcutsQuery::class)->recent($user));
        $this->assertSame(['orders'], app(UserShortcutsQuery::class)->recent($other));
    }

    public function test_pin_position_is_reassigned_on_every_save(): void
    {
        $tenant = $this->createTenant();
        $user = $this->createMember($tenant);
        $this->actingAsTenant($tenant);

        app(SetPinnedShortcutsAction::class)->execute($user, ['orders', 'customers']);
        app(SetPinnedShortcutsAction::class)->execute($user, ['customers', 'orders']);

        $positions = UserShortcut::query()
            ->where('user_id', $user->getKey())
            ->orderBy('position')
            ->pluck('nav_key')
            ->all();

        $this->assertSame(['customers', 'orders'], $positions);
    }
}
