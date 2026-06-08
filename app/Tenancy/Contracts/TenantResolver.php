<?php

declare(strict_types=1);

namespace App\Tenancy\Contracts;

use App\Models\Tenant;

/**
 * Resolves a tenant from request identifiers. Implementations MUST NOT query
 * business (tenant-scoped) tables — only the central tenants/domains tables.
 *
 * Backed by a driver-specific adapter (see app/Tenancy/Adapters).
 */
interface TenantResolver
{
    /** Resolve a tenant from a request host (subdomain), or null. */
    public function resolveFromSubdomain(string $host): ?Tenant;

    /** Resolve a tenant by its primary key, or null. */
    public function resolveById(string $tenantId): ?Tenant;
}
