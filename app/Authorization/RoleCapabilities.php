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
     * @return array<TenantRole, list<string>>
     */
    public static function map(): array
    {
        return [
            TenantRole::Admin->value => [self::WILDCARD],
            TenantRole::Team->value => [
                'customers.view',
                'customers.manage',
                'pricing.view',
                'pricing.manage',
                'inventory.view',
                'inventory.manage',
                'orders.view',
                'orders.manage',
                'finance.view',
                'finance.manage',
                'financials.view',
                // customers.delete, customers.tokens, inventory.delete,
                // orders.delete, orders.backorder and finance.delete are ADMIN-only.
            ],
            TenantRole::Cellar->value => [
                // Filled in as the Cellar module lands.
            ],
            TenantRole::Orders->value => [
                'orders.view',
                'orders.manage',
                'finance.view',
                'finance.manage',
                'financials.view',
            ],
            TenantRole::Manager->value => [
                'finance.view',
                'finance.manage',
                'financials.view',
            ],
            TenantRole::Sales->value => [
                'finance.view',
                'financials.view',
            ],
            // Roles below accumulate capabilities as their modules land.
            TenantRole::Hospitality->value => [],
            TenantRole::Kitchen->value => [],
            TenantRole::Employee->value => [],
            TenantRole::WineClub->value => [],
            TenantRole::Inventory->value => [
                'inventory.view',
                'inventory.manage',
            ],
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
