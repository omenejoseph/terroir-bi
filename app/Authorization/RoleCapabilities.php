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
                // customers.delete, customers.tokens and inventory.delete are ADMIN-only.
            ],
            TenantRole::Cellar->value => [
                // Filled in as the Cellar module lands.
            ],
            TenantRole::Orders->value => [
                // Filled in as the Orders module lands.
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
