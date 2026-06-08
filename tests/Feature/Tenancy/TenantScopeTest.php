<?php

declare(strict_types=1);

namespace Tests\Feature\Tenancy;

use App\Models\User;
use App\Tenancy\Exceptions\CrossTenantException;
use App\Tenancy\Exceptions\NoTenantContextException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithTenancy;
use Tests\TestCase;

class TenantScopeTest extends TestCase
{
    use InteractsWithTenancy;
    use RefreshDatabase;

    public function test_queries_are_scoped_to_the_current_tenant(): void
    {
        $a = $this->createTenant();
        $this->actingAsTenant($a);
        User::factory()->create(['email' => 'a@example.com']);

        $b = $this->createTenant();
        $this->actingAsTenant($b);
        User::factory()->create(['email' => 'b@example.com']);

        $this->assertSame(1, User::count());
        $first = User::first();
        $this->assertNotNull($first);
        $this->assertSame('b@example.com', $first->email);
    }

    public function test_cross_tenant_find_returns_null(): void
    {
        $a = $this->createTenant();
        $this->actingAsTenant($a);
        $userA = User::factory()->create();

        $b = $this->createTenant();
        $this->actingAsTenant($b);

        $this->assertNull(User::find($userA->getKey()));
    }

    public function test_scope_fails_closed_when_no_tenant_is_bound(): void
    {
        $this->forgetTenant();

        $this->expectException(NoTenantContextException::class);

        User::count();
    }

    public function test_tenant_id_is_assigned_automatically_on_create(): void
    {
        $tenant = $this->createTenant();
        $this->actingAsTenant($tenant);

        $user = User::factory()->create();

        $this->assertSame($tenant->getKey(), $user->tenant_id);
    }

    public function test_creating_without_tenant_context_fails_closed(): void
    {
        $this->forgetTenant();

        $this->expectException(NoTenantContextException::class);

        $user = new User([
            'name' => 'X',
            'email' => 'x@example.com',
            'password' => 'secret',
            'role' => 'ADMIN',
        ]);
        $user->save();
    }

    public function test_writing_a_record_for_another_tenant_is_blocked(): void
    {
        $a = $this->createTenant();
        $b = $this->createTenant();

        $this->actingAsTenant($a);

        $this->expectException(CrossTenantException::class);

        $user = new User([
            'tenant_id' => $b->getKey(),
            'name' => 'X',
            'email' => 'x@example.com',
            'password' => 'secret',
            'role' => 'ADMIN',
        ]);
        $user->save();
    }

    public function test_without_tenant_escape_hatch_reads_across_tenants(): void
    {
        $a = $this->createTenant();
        $this->actingAsTenant($a);
        User::factory()->create();

        $b = $this->createTenant();
        $this->actingAsTenant($b);
        User::factory()->create();

        $this->assertSame(1, User::count());
        $this->assertSame(2, User::withoutTenant()->count());
    }

    public function test_email_is_unique_per_tenant_not_globally(): void
    {
        $a = $this->createTenant();
        $b = $this->createTenant();

        $this->actingAsTenant($a);
        User::factory()->create(['email' => 'same@example.com']);

        // Same email under a different tenant is allowed.
        $this->actingAsTenant($b);
        $userB = User::factory()->create(['email' => 'same@example.com']);
        $this->assertNotNull($userB->getKey());

        // Duplicate within the same tenant is rejected.
        $this->actingAsTenant($a);
        $this->expectException(QueryException::class);
        User::factory()->create(['email' => 'same@example.com']);
    }
}
