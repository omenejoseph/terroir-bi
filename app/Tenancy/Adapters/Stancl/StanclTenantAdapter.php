<?php

declare(strict_types=1);

namespace App\Tenancy\Adapters\Stancl;

use App\Models\Tenant;
use App\Tenancy\Contracts\TenantResolver;
use App\Tenancy\Exceptions\UnsupportedIsolationModeException;
use Illuminate\Support\Str;

/**
 * The stancl/tenancy adapter. This is the ONLY application file (besides the
 * Tenant model's StanclTenantModelTrait) that is coupled to a tenancy driver.
 *
 * In shared_row mode we resolve tenants ourselves via the central tenants table
 * and never invoke stancl's bootstrappers. The dedicated-DB path is stubbed: it
 * is where stancl's database tenancy (tenancy()->initialize()) would be wired in
 * to support "mixed mode" tenants on their own databases.
 */
class StanclTenantAdapter implements TenantResolver
{
    public function resolveFromSubdomain(string $host): ?Tenant
    {
        $slug = $this->extractSlug($host);

        if ($slug === null) {
            return null;
        }

        // Tenant is not tenant-scoped, so this query is already global.
        return Tenant::query()->where('slug', $slug)->first();
    }

    public function resolveById(string $tenantId): ?Tenant
    {
        return Tenant::query()->find($tenantId);
    }

    /**
     * FUTURE (mixed mode): initialize stancl database tenancy for a dedicated-DB
     * tenant, e.g. tenancy()->initialize($tenant) with DatabaseTenancyBootstrapper
     * re-enabled in config/tenancy.php. Not yet enabled.
     */
    public function initializeDatabase(Tenant $tenant): void
    {
        throw new UnsupportedIsolationModeException(
            "Dedicated-database isolation is not yet enabled (tenant {$tenant->getKey()})."
        );
    }

    /** Counterpart to initializeDatabase(); ends database tenancy. */
    public function endDatabase(): void
    {
        throw new UnsupportedIsolationModeException('Dedicated-database isolation is not yet enabled.');
    }

    /**
     * Reduce a request host to a tenant slug. Returns null for central domains
     * (no subdomain) so they don't resolve to a tenant.
     */
    private function extractSlug(string $host): ?string
    {
        $host = Str::lower($host);

        // Strip a leading port if present.
        $host = Str::before($host, ':');

        foreach (config('tenant.central_domains', []) as $central) {
            $central = Str::lower(trim($central));

            if ($host === $central) {
                return null;
            }

            if (Str::endsWith($host, '.'.$central)) {
                $subdomain = Str::beforeLast($host, '.'.$central);

                // Only a single-label subdomain (acme.localhost), not a.b.localhost.
                return Str::contains($subdomain, '.') ? null : ($subdomain ?: null);
            }
        }

        return null;
    }
}
