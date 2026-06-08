<?php

declare(strict_types=1);

namespace Tests\Feature\Tenancy;

use App\Models\TranslationOverride;
use App\Tenancy\Exceptions\CrossTenantException;
use App\Tenancy\Exceptions\NoTenantContextException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithTenancy;
use Tests\TestCase;

/**
 * Exercises the tenant global scope via a tenant-owned model
 * (TranslationOverride). Users are global and intentionally not scoped.
 */
class TenantScopeTest extends TestCase
{
    use InteractsWithTenancy;
    use RefreshDatabase;

    public function test_queries_are_scoped_to_the_current_tenant(): void
    {
        $a = $this->createTenant();
        $this->actingAsTenant($a);
        TranslationOverride::create(['locale' => 'hr', 'key' => 'a', 'value' => 'A']);

        $b = $this->createTenant();
        $this->actingAsTenant($b);
        TranslationOverride::create(['locale' => 'hr', 'key' => 'b', 'value' => 'B']);

        $this->assertSame(1, TranslationOverride::count());
        $this->assertSame('b', TranslationOverride::firstOrFail()->key);
    }

    public function test_cross_tenant_find_returns_null(): void
    {
        $a = $this->createTenant();
        $this->actingAsTenant($a);
        $rowA = TranslationOverride::create(['locale' => 'hr', 'key' => 'a', 'value' => 'A']);

        $this->actingAsTenant($this->createTenant());

        $this->assertNull(TranslationOverride::find($rowA->getKey()));
    }

    public function test_scope_fails_closed_when_no_tenant_is_bound(): void
    {
        $this->forgetTenant();

        $this->expectException(NoTenantContextException::class);

        TranslationOverride::count();
    }

    public function test_tenant_id_is_assigned_automatically_on_create(): void
    {
        $tenant = $this->createTenant();
        $this->actingAsTenant($tenant);

        $row = TranslationOverride::create(['locale' => 'hr', 'key' => 'a', 'value' => 'A']);

        $this->assertSame($tenant->getKey(), $row->tenant_id);
    }

    public function test_creating_without_tenant_context_fails_closed(): void
    {
        $this->forgetTenant();

        $this->expectException(NoTenantContextException::class);

        $row = new TranslationOverride(['locale' => 'hr', 'key' => 'a', 'value' => 'A']);
        $row->save();
    }

    public function test_writing_a_record_for_another_tenant_is_blocked(): void
    {
        $a = $this->createTenant();
        $b = $this->createTenant();
        $this->actingAsTenant($a);

        $this->expectException(CrossTenantException::class);

        $row = new TranslationOverride(['locale' => 'hr', 'key' => 'a', 'value' => 'A']);
        $row->tenant_id = $b->getKey(); // not mass-assignable; set explicitly
        $row->save();
    }

    public function test_without_tenant_escape_hatch_reads_across_tenants(): void
    {
        $a = $this->createTenant();
        $this->actingAsTenant($a);
        TranslationOverride::create(['locale' => 'hr', 'key' => 'a', 'value' => 'A']);

        $this->actingAsTenant($this->createTenant());
        TranslationOverride::create(['locale' => 'hr', 'key' => 'b', 'value' => 'B']);

        $this->assertSame(1, TranslationOverride::count());
        $this->assertSame(2, TranslationOverride::withoutTenant()->count());
    }
}
