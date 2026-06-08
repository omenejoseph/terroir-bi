<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\TranslationOverride;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithTenancy;
use Tests\TestCase;

class TranslationApiTest extends TestCase
{
    use InteractsWithTenancy;
    use RefreshDatabase;

    /** @return array<string, string> */
    private function tenantHeader(string $tenantId): array
    {
        return ['X-Tenant' => $tenantId];
    }

    public function test_unidentified_tenant_is_rejected(): void
    {
        $this->getJson('/api/v1/translations?locale=hr')->assertNotFound();
    }

    public function test_admin_can_upsert_and_read_an_override(): void
    {
        $tenant = $this->createTenant();

        $this->putJson('/api/v1/translations', [
            'locale' => 'hr',
            'key' => 'orders.title',
            'value' => 'Narudžbe',
        ], $this->tenantHeader($tenant->getKey()))
            ->assertOk()
            ->assertJsonPath('data.key', 'orders.title')
            ->assertJsonPath('data.value', 'Narudžbe');

        $this->getJson('/api/v1/translations?locale=hr', $this->tenantHeader($tenant->getKey()))
            ->assertOk()
            ->assertExactJson(['data' => ['orders.title' => 'Narudžbe']]);
    }

    public function test_overrides_do_not_leak_across_tenants_over_http(): void
    {
        $a = $this->createTenant();
        $b = $this->createTenant();

        $this->putJson('/api/v1/translations', [
            'locale' => 'hr', 'key' => 'k', 'value' => 'A',
        ], $this->tenantHeader($a->getKey()))->assertOk();

        $this->getJson('/api/v1/translations?locale=hr', $this->tenantHeader($b->getKey()))
            ->assertOk()
            ->assertExactJson(['data' => []]);
    }

    public function test_validation_rejects_unsupported_locale(): void
    {
        $tenant = $this->createTenant();

        $this->putJson('/api/v1/translations', [
            'locale' => 'de', 'key' => 'k', 'value' => 'v',
        ], $this->tenantHeader($tenant->getKey()))
            ->assertUnprocessable()
            ->assertJsonValidationErrorFor('locale');
    }

    public function test_admin_can_delete_an_override(): void
    {
        $tenant = $this->createTenant();
        $this->actingAsTenant($tenant);
        TranslationOverride::create(['locale' => 'hr', 'key' => 'k', 'value' => 'v']);
        $this->forgetTenant();

        $this->deleteJson('/api/v1/translations', [
            'locale' => 'hr', 'key' => 'k',
        ], $this->tenantHeader($tenant->getKey()))->assertNoContent();

        $this->actingAsTenant($tenant);
        $this->assertSame(0, TranslationOverride::count());
    }
}
