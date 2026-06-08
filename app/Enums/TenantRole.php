<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Roles a user can hold within a tenant. A membership may hold several. The
 * capability each role grants is defined centrally in
 * App\Authorization\RoleCapabilities (kept out of the enum so authorization can
 * later be swapped for a package without touching the enum).
 */
enum TenantRole: string
{
    case Admin = 'ADMIN';
    case Team = 'TEAM';
    case Cellar = 'CELLAR';
    case Orders = 'ORDERS';

    public function isAdmin(): bool
    {
        return $this === self::Admin;
    }

    public function label(): string
    {
        return ucfirst(strtolower($this->value));
    }
}
