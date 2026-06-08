<?php

declare(strict_types=1);

namespace Tests\Feature\Tenancy;

use App\Tenancy\Contracts\TenantResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithTenancy;
use Tests\TestCase;

class TenantResolutionTest extends TestCase
{
    use InteractsWithTenancy;
    use RefreshDatabase;

    public function test_resolves_a_tenant_from_a_subdomain(): void
    {
        $tenant = $this->createTenant(['slug' => 'acme']);

        $resolved = app(TenantResolver::class)->resolveFromSubdomain('acme.localhost');

        $this->assertNotNull($resolved);
        $this->assertSame($tenant->getKey(), $resolved->getKey());
    }

    public function test_central_domain_resolves_to_no_tenant(): void
    {
        $this->createTenant(['slug' => 'acme']);

        $this->assertNull(app(TenantResolver::class)->resolveFromSubdomain('localhost'));
    }

    public function test_unknown_subdomain_resolves_to_no_tenant(): void
    {
        $this->assertNull(app(TenantResolver::class)->resolveFromSubdomain('nope.localhost'));
    }

    public function test_resolves_a_tenant_by_id_across_contexts(): void
    {
        $tenant = $this->createTenant();

        $resolved = app(TenantResolver::class)->resolveById($tenant->getKey());

        $this->assertNotNull($resolved);
        $this->assertSame($tenant->getKey(), $resolved->getKey());
    }
}
