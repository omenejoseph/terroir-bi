<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Enums\TenantRole;
use App\Models\TranslationOverride;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\InteractsWithTenancy;
use Tests\TestCase;

class TranslationApiTest extends TestCase
{
    use InteractsWithTenancy;
    use RefreshDatabase;

    public function test_unauthenticated_request_is_rejected(): void
    {
        $this->getJson('/api/v1/translations?locale=hr')->assertUnauthorized();
    }

    public function test_non_member_cannot_access_a_tenant(): void
    {
        $tenant = $this->createTenant();
        Sanctum::actingAs($this->createUser());

        $this->getJson('/api/v1/translations?locale=hr', $this->tenantHeader($tenant))
            ->assertForbidden();
    }

    public function test_admin_can_upsert_and_read_an_override(): void
    {
        $tenant = $this->createTenant();
        Sanctum::actingAs($this->createMember($tenant, [TenantRole::Admin]));

        $this->putJson('/api/v1/translations', [
            'locale' => 'hr',
            'key' => 'orders.title',
            'value' => 'Narudžbe',
        ], $this->tenantHeader($tenant))
            ->assertOk()
            ->assertJsonPath('data.key', 'orders.title');

        $this->getJson('/api/v1/translations?locale=hr', $this->tenantHeader($tenant))
            ->assertOk()
            ->assertExactJson(['data' => ['orders.title' => 'Narudžbe']]);
    }

    public function test_non_admin_member_cannot_write_overrides(): void
    {
        $tenant = $this->createTenant();
        Sanctum::actingAs($this->createMember($tenant, [TenantRole::Team]));

        // A team member can read…
        $this->getJson('/api/v1/translations?locale=hr', $this->tenantHeader($tenant))->assertOk();

        // …but not write.
        $this->putJson('/api/v1/translations', [
            'locale' => 'hr', 'key' => 'k', 'value' => 'v',
        ], $this->tenantHeader($tenant))->assertForbidden();
    }

    public function test_overrides_do_not_leak_across_tenants(): void
    {
        $a = $this->createTenant();
        $admin = $this->createMember($a, [TenantRole::Admin]);
        $b = $this->createTenant();

        Sanctum::actingAs($admin);
        $this->putJson('/api/v1/translations', [
            'locale' => 'hr', 'key' => 'k', 'value' => 'A',
        ], $this->tenantHeader($a))->assertOk();

        // Same admin is not a member of B.
        $this->getJson('/api/v1/translations?locale=hr', $this->tenantHeader($b))
            ->assertForbidden();
    }

    public function test_admin_can_delete_an_override(): void
    {
        $tenant = $this->createTenant();
        $admin = $this->createMember($tenant, [TenantRole::Admin]);

        $this->actingAsTenant($tenant);
        TranslationOverride::create(['locale' => 'hr', 'key' => 'k', 'value' => 'v']);
        $this->forgetTenant();

        Sanctum::actingAs($admin);
        $this->deleteJson('/api/v1/translations', [
            'locale' => 'hr', 'key' => 'k',
        ], $this->tenantHeader($tenant))->assertNoContent();

        $this->actingAsTenant($tenant);
        $this->assertSame(0, TranslationOverride::count());
    }
}
