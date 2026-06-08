<?php

declare(strict_types=1);

namespace App\Authorization\Contracts;

use App\Enums\TenantRole;

/**
 * Authorization for the current user within the current tenant.
 *
 * This is the seam the rest of the app (and Gates) depend on. The default
 * implementation reads the active membership; it could be swapped for a
 * package-backed one (e.g. spatie/laravel-permission with teams) without
 * changing call sites.
 */
interface Authorizer
{
    /**
     * The roles held in the current tenant.
     *
     * @return list<TenantRole>
     */
    public function roles(): array;

    public function hasRole(TenantRole $role): bool;

    /** Whether the current user holds the given capability in the current tenant. */
    public function can(string $capability): bool;
}
