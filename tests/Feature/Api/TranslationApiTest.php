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

    public function test_member_reads_the_global_overrides(): void
    {
        $tenant = $this->createTenant();
        // Overrides are platform-wide (managed in the back office), not per tenant.
        TranslationOverride::create(['locale' => 'hr', 'key' => 'orders.title', 'value' => 'Narudžbe']);

        Sanctum::actingAs($this->createMember($tenant, [TenantRole::Team]));

        $this->getJson('/api/v1/translations?locale=hr', $this->tenantHeader($tenant))
            ->assertOk()
            ->assertExactJson(['data' => ['orders.title' => 'Narudžbe']]);
    }
}
