<?php

declare(strict_types=1);

namespace Tests\Feature\Members;

use App\Authorization\MembershipContext;
use App\Authorization\ModuleRegistry;
use App\Authorization\RoleCapabilities;
use App\Enums\TenantRole;
use App\Services\Auth\ActiveTenantSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\InteractsWithTenancy;
use Tests\TestCase;

/**
 * What each role can do must stay what it was in the original app (its sidebar, page guards and
 * `requireRole(...)` calls on every server action). This pins the whole matrix, so changing a
 * role's powers is a deliberate edit to this file, not a side effect.
 *
 * Where the old app's own menu and guards disagreed with its looser server actions (e.g. any
 * signed-in user could read the customer list through an action while only admins saw the menu
 * entry) the menu and page guards are what is pinned.
 */
class RoleParityTest extends TestCase
{
    use InteractsWithTenancy;
    use RefreshDatabase;

    /** @return array<string, list<string>> role value => capabilities (ADMIN holds everything). */
    private static function expected(): array
    {
        return [
            'TEAM' => ['customers.create', 'financials.view', 'inventory.manage', 'inventory.stock', 'inventory.view', 'orders.manage', 'orders.view', 'pricing.view', 'production.manage', 'production.view', 'supplier_orders.view', 'work_orders.use'],
            'CELLAR' => ['cellar.manage', 'cellar.view', 'vineyards.manage', 'vineyards.view', 'work_orders.use'],
            'ORDERS' => ['customers.create', 'financials.view', 'orders.manage', 'orders.view', 'pricing.view', 'work_orders.use'],
            'MANAGER' => ['financials.view'],
            'SALES' => ['financials.view'],
            'INVENTORY' => ['inventory.stock', 'inventory.view', 'pricing.view'],
            'HOSPITALITY' => [],
            'KITCHEN' => [],
            'EMPLOYEE' => [],
            'WINE_CLUB' => [],
        ];
    }

    public function test_every_role_holds_exactly_the_old_apps_capabilities(): void
    {
        foreach (self::expected() as $role => $caps) {
            $actual = RoleCapabilities::grants(TenantRole::from($role));
            sort($actual);

            $this->assertSame($caps, $actual, "{$role} drifted from the old app");
        }

        $this->assertSame(['*'], RoleCapabilities::grants(TenantRole::Admin));
    }

    public function test_every_role_is_covered_by_the_matrix(): void
    {
        $all = [];
        foreach (TenantRole::cases() as $role) {
            if ($role !== TenantRole::Admin) {
                $all[] = $role->value;
            }
        }
        $covered = array_keys(self::expected());
        sort($all);
        sort($covered);

        $this->assertSame($all, $covered);
    }

    /** @return array<string, array{string}> capabilities only an ADMIN held in the old app */
    public static function adminOnly(): array
    {
        $caps = [
            'members.view', 'members.manage', 'members.impersonate', 'invitations.manage', 'settings.manage', 'logs.view', 'translations.manage',
            'finance.view', 'finance.manage', 'finance.delete',
            'suppliers.view', 'suppliers.manage', 'suppliers.delete',
            'customers.view', 'customers.manage', 'customers.delete', 'customers.tokens',
            'pricing.manage',
            'inventory.bulk', 'inventory.analytics', 'inventory.delete',
            'orders.delete', 'orders.backorder',
            'cellar.delete', 'vineyards.delete', 'production.delete',
            'ai.use', 'ai.manage',
        ];

        return array_combine($caps, array_map(fn (string $c): array => [$c], $caps));
    }

    /** @dataProvider adminOnly */
    #[DataProvider('adminOnly')]
    public function test_admin_only_capabilities_are_held_by_no_other_role(string $capability): void
    {
        foreach (TenantRole::cases() as $role) {
            $this->assertSame(
                $role === TenantRole::Admin,
                RoleCapabilities::roleGrants($role, $capability),
                "{$role->value} and {$capability}",
            );
        }
    }

    public function test_every_registered_capability_is_accounted_for(): void
    {
        $held = array_unique(array_merge(...array_values(self::expected())));
        $adminOnly = array_keys(self::adminOnly());
        $registered = array_unique(array_merge(...array_values(ModuleRegistry::capabilities())));

        // A capability added to the registry must be classified here, so it cannot slip in unreviewed.
        $unclassified = array_values(array_diff($registered, $held, $adminOnly));
        $this->assertSame([], $unclassified, 'classify these in RoleParityTest: '.implode(', ', $unclassified));
    }

    // ── members holding several roles ───────────────────────────────────────

    /** @param list<TenantRole> $roles */
    private function can(array $roles, string $capability): bool
    {
        $tenant = $this->createTenant();
        $member = $this->createMember($tenant, $roles);
        $context = app(MembershipContext::class);
        $context->set($member->membershipFor($tenant) ?? throw new \LogicException('no membership'));

        return $context->can($capability);
    }

    public function test_roles_add_up(): void
    {
        $this->assertTrue($this->can([TenantRole::Cellar, TenantRole::Orders, TenantRole::Inventory], 'inventory.stock'));
        $this->assertTrue($this->can([TenantRole::Cellar, TenantRole::Orders, TenantRole::Inventory], 'cellar.manage'));
        $this->assertTrue($this->can([TenantRole::Cellar, TenantRole::Orders, TenantRole::Inventory], 'orders.manage'));
        $this->assertFalse($this->can([TenantRole::Cellar, TenantRole::Orders, TenantRole::Inventory], 'finance.view'));
        $this->assertFalse($this->can([TenantRole::Orders, TenantRole::Inventory], 'inventory.manage'), 'creating items was TEAM-only');
    }

    public function test_a_manager_never_gets_work_orders_even_alongside_another_role(): void
    {
        $this->assertTrue($this->can([TenantRole::Orders], 'work_orders.use'));
        $this->assertFalse($this->can([TenantRole::Manager, TenantRole::Orders], 'work_orders.use'));
        $this->assertFalse($this->can([TenantRole::Manager, TenantRole::Employee, TenantRole::Orders], 'work_orders.use'));
        // The exclusion is only for work orders…
        $this->assertTrue($this->can([TenantRole::Manager, TenantRole::Orders], 'orders.manage'));
        // …and an ADMIN always sees everything.
        $this->assertTrue($this->can([TenantRole::Manager, TenantRole::Admin], 'work_orders.use'));
    }

    public function test_admin_can_do_everything(): void
    {
        foreach (array_keys(self::adminOnly()) as $capability) {
            $this->assertTrue($this->can([TenantRole::Admin], $capability), $capability);
        }
    }

    // ── the routes sit behind the right capability ──────────────────────────

    /** @return array<string, array{string, string}> route name => capability that must guard it */
    public static function guardedRoutes(): array
    {
        $map = [
            'inventory.stock.adjust' => 'inventory.stock',
            'inventory.bulk-update' => 'inventory.bulk',
            'inventory.check.apply' => 'inventory.bulk',
            'inventory.bulk-import' => 'inventory.bulk',
            'inventory.duplicate' => 'inventory.bulk',
            'inventory.analytics' => 'inventory.analytics',
            'inventory.spend' => 'inventory.analytics',
            'inventory.store' => 'inventory.manage',
            'inventory.update' => 'inventory.manage',
            'inventory.destroy' => 'inventory.delete',
            'customers.store' => 'customers.create',
            'customers.contacted' => 'customers.create',
            'customers.reorder-radar' => 'customers.create',
            'customers.update' => 'customers.manage',
            'customers.index' => 'customers.view',
            'customers.destroy' => 'customers.delete',
            'work-orders.index' => 'work_orders.use',
            'work-orders.store' => 'work_orders.use',
            'work-orders.destroy' => 'work_orders.use',
            'work-order-boards.store' => 'work_orders.use',
            'team.index' => 'members.view',
            'team.impersonate' => 'members.impersonate',
        ];

        $out = [];
        foreach ($map as $name => $cap) {
            $out["{$name} needs {$cap}"] = [$name, $cap];
        }

        return $out;
    }

    /** @dataProvider guardedRoutes */
    #[DataProvider('guardedRoutes')]
    public function test_web_route_guard(string $name, string $capability): void
    {
        $route = Route::getRoutes()->getByName($name);
        $this->assertNotNull($route, "route {$name} missing");

        $this->assertContains("can:{$capability}", $route->gatherMiddleware(), "{$name} is not behind {$capability}");
    }

    /** @return array<string, array{string, string, int}> role => url => status */
    public static function pages(): array
    {
        $rows = [];
        $matrix = [
            'ADMIN' => ['/orders' => 200, '/inventory' => 200, '/customers' => 200, '/work-orders' => 200, '/settings' => 200, '/settings/team' => 200, '/logs' => 200, '/inventory-analytics' => 200],
            'TEAM' => ['/orders' => 200, '/inventory' => 200, '/customers' => 403, '/work-orders' => 200, '/settings' => 403, '/settings/team' => 403, '/logs' => 403, '/inventory-analytics' => 403],
            'ORDERS' => ['/orders' => 200, '/inventory' => 403, '/customers' => 403, '/work-orders' => 200, '/settings' => 403, '/settings/team' => 403, '/logs' => 403, '/inventory-analytics' => 403],
            'CELLAR' => ['/orders' => 403, '/inventory' => 403, '/customers' => 403, '/work-orders' => 200, '/settings' => 403, '/settings/team' => 403, '/logs' => 403, '/inventory-analytics' => 403],
            'INVENTORY' => ['/orders' => 403, '/inventory' => 200, '/customers' => 403, '/work-orders' => 403, '/settings' => 403, '/settings/team' => 403, '/logs' => 403, '/inventory-analytics' => 403],
            'MANAGER' => ['/orders' => 403, '/inventory' => 403, '/customers' => 403, '/work-orders' => 403, '/settings' => 403, '/settings/team' => 403, '/logs' => 403, '/inventory-analytics' => 403],
            'SALES' => ['/orders' => 403, '/inventory' => 403, '/customers' => 403, '/work-orders' => 403, '/settings' => 403, '/settings/team' => 403, '/logs' => 403, '/inventory-analytics' => 403],
            'HOSPITALITY' => ['/orders' => 403, '/inventory' => 403, '/customers' => 403, '/work-orders' => 403, '/settings' => 403, '/settings/team' => 403, '/logs' => 403, '/inventory-analytics' => 403],
        ];
        foreach ($matrix as $role => $urls) {
            foreach ($urls as $url => $status) {
                $rows["{$role} {$url} → {$status}"] = [$role, $url, $status];
            }
        }

        return $rows;
    }

    /** @dataProvider pages */
    #[DataProvider('pages')]
    public function test_each_role_reaches_exactly_the_old_apps_pages(string $role, string $url, int $status): void
    {
        $tenant = $this->createTenant();
        $member = $this->createMember($tenant, [TenantRole::from($role)]);

        $this->actingAs($member)->withSession([ActiveTenantSession::KEY => $tenant->getKey()])->get($url)->assertStatus($status);
    }
}
