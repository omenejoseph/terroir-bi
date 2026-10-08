<?php

declare(strict_types=1);

namespace App\Authorization;

use App\Enums\TenantRole;

/**
 * The single source of truth mapping roles to capabilities.
 *
 * Capabilities are dotted strings (e.g. "members.manage") checked via Gates.
 * ADMIN is a superuser within its tenant and is granted the "*" wildcard.
 * Other roles accumulate capabilities as modules land.
 *
 * Keeping the map here (rather than in spatie tables or scattered Gate closures)
 * means authorization is auditable in one place and the backing implementation
 * can be swapped without moving the policy.
 */
final class RoleCapabilities
{
    public const WILDCARD = '*';

    /**
     * Each role's capabilities, mirroring what that role could do in the original app
     * (every `requireRole(...)` / `hasAnyRole(...)` in its pages and server actions, and its
     * sidebar). ADMIN is the only role that could do the rest, which is why a capability
     * listed for no role below (members.*, settings.manage, finance.*, suppliers.*, …) is
     * admin-only. Pinned by tests/Feature/Members/RoleParityTest.
     *
     * Where the old app was looser than its own menu (e.g. any signed-in user could read the
     * customer list through a server action, while the menu showed Customers to admins only)
     * the menu and page guards win, since that is what a person actually experienced.
     *
     * @return array<TenantRole, list<string>>
     */
    public static function map(): array
    {
        return [
            TenantRole::Admin->value => [self::WILDCARD],
            TenantRole::Team->value => [
                'customers.create',
                'inventory.view',
                'inventory.manage',
                'inventory.stock',
                'orders.view',
                'orders.manage',
                'pricing.view',
                'production.view',
                'production.manage',
                'supplier_orders.view',
                'work_orders.use',
                'financials.view',
            ],
            TenantRole::Cellar->value => [
                'cellar.view',
                'cellar.manage',
                'vineyards.view',
                'vineyards.manage',
                'work_orders.use',
            ],
            TenantRole::Orders->value => [
                'customers.create',
                'orders.view',
                'orders.manage',
                'pricing.view',
                'work_orders.use',
                'financials.view',
            ],
            // The old MANAGER role was about people (schedules, "My Team"), which this app
            // does not have yet; the only business power it carried was seeing figures.
            TenantRole::Manager->value => [
                'financials.view',
            ],
            // Sales worked in the pipeline (not built here yet) and could see figures.
            TenantRole::Sales->value => [
                'financials.view',
            ],
            // Roles below belong to modules that are not built here yet.
            TenantRole::Hospitality->value => [],
            TenantRole::Kitchen->value => [],
            TenantRole::Employee->value => [],
            TenantRole::WineClub->value => [],
            TenantRole::Inventory->value => [
                'inventory.view',
                'inventory.stock',
                'pricing.view',
            ],
        ];
    }

    /**
     * Capabilities that holding a given role takes AWAY, even when another of the member's
     * roles grants them. The old app's sidebar hid Work Orders from anyone with MANAGER, so
     * a MANAGER who was also on ORDERS still did not see it. ADMIN is never restricted.
     *
     * @return array<string, list<TenantRole>>
     */
    public static function revokedBy(): array
    {
        return [
            'work_orders.use' => [TenantRole::Manager],
        ];
    }

    /**
     * @return list<string>
     */
    public static function grants(TenantRole $role): array
    {
        return self::map()[$role->value] ?? [];
    }

    public static function roleGrants(TenantRole $role, string $capability): bool
    {
        $grants = self::grants($role);

        return in_array(self::WILDCARD, $grants, true) || in_array($capability, $grants, true);
    }
}
