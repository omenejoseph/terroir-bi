<?php

declare(strict_types=1);

namespace Tests\Feature\Tenancy;

use App\Models\PricingTier;
use App\Tenancy\Exceptions\CrossTenantException;
use App\Tenancy\Exceptions\NoTenantContextException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithTenancy;
use Tests\TestCase;

/**
 * Exercises the tenant global scope via a tenant-owned model (PricingTier).
 * Users are global and intentionally not scoped.
 */
class TenantScopeTest extends TestCase
{
    use InteractsWithTenancy;
    use RefreshDatabase;

    public function test_queries_are_scoped_to_the_current_tenant(): void
    {
        $a = $this->createTenant();
        $this->actingAsTenant($a);
        PricingTier::create(['name' => 'A']);

        $b = $this->createTenant();
        $this->actingAsTenant($b);
        PricingTier::create(['name' => 'B']);

        $this->assertSame(1, PricingTier::count());
        $this->assertSame('B', PricingTier::firstOrFail()->name);
    }

    public function test_cross_tenant_find_returns_null(): void
    {
        $a = $this->createTenant();
        $this->actingAsTenant($a);
        $rowA = PricingTier::create(['name' => 'A']);

        $this->actingAsTenant($this->createTenant());

        $this->assertNull(PricingTier::find($rowA->getKey()));
    }

    public function test_scope_fails_closed_when_no_tenant_is_bound(): void
    {
        $this->forgetTenant();

        $this->expectException(NoTenantContextException::class);

        PricingTier::count();
    }

    public function test_tenant_id_is_assigned_automatically_on_create(): void
    {
        $tenant = $this->createTenant();
        $this->actingAsTenant($tenant);

        $row = PricingTier::create(['name' => 'A']);

        $this->assertSame($tenant->getKey(), $row->tenant_id);
    }

    public function test_creating_without_tenant_context_fails_closed(): void
    {
        $this->forgetTenant();

        $this->expectException(NoTenantContextException::class);

        $row = new PricingTier(['name' => 'A']);
        $row->save();
    }

    public function test_writing_a_record_for_another_tenant_is_blocked(): void
    {
        $a = $this->createTenant();
        $b = $this->createTenant();
        $this->actingAsTenant($a);

        $this->expectException(CrossTenantException::class);

        $row = new PricingTier(['name' => 'A']);
        $row->tenant_id = $b->getKey(); // not mass-assignable; set explicitly
        $row->save();
    }

    public function test_without_tenant_escape_hatch_reads_across_tenants(): void
    {
        $a = $this->createTenant();
        $this->actingAsTenant($a);
        PricingTier::create(['name' => 'A']);

        $this->actingAsTenant($this->createTenant());
        PricingTier::create(['name' => 'B']);

        $this->assertSame(1, PricingTier::count());
        $this->assertSame(2, PricingTier::withoutTenant()->count());
    }
}
