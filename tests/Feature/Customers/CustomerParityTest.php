<?php

declare(strict_types=1);

namespace Tests\Feature\Customers;

use App\Enums\TenantRole;
use App\Models\Customer;
use App\Models\InventoryItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\InteractsWithTenancy;
use Tests\TestCase;

class CustomerParityTest extends TestCase
{
    use InteractsWithTenancy;
    use RefreshDatabase;

    public function test_new_parity_fields_round_trip(): void
    {
        $tenant = $this->createTenant();
        $admin = $this->createMember($tenant, [TenantRole::Admin]);
        Sanctum::actingAs($admin);

        $id = $this->postJson('/api/v1/customers', [
            'company_name' => 'Konoba Mare',
            'email' => 'mare@example.com',
            'oib' => '12345678901',
            'customer_type' => 'RESTAURANT',
            'is_agency' => true,
            'allow_single_bottle' => true,
        ], $this->tenantHeader($tenant))
            ->assertCreated()
            ->assertJsonPath('data.oib', '12345678901')
            ->assertJsonPath('data.customer_type', 'RESTAURANT')
            ->assertJsonPath('data.is_agency', true)
            ->assertJsonPath('data.allow_single_bottle', true)
            ->json('data.id');

        $this->getJson("/api/v1/customers/{$id}", $this->tenantHeader($tenant))
            ->assertOk()
            ->assertJsonPath('data.is_agency', true);
    }

    public function test_product_overrides_can_be_set_listed_and_removed(): void
    {
        $tenant = $this->createTenant();
        $admin = $this->createMember($tenant, [TenantRole::Admin]);
        $this->actingAsTenant($tenant);
        $customer = Customer::create(['company_name' => 'C', 'email' => 'c@example.com']);
        $item = InventoryItem::create(['name' => 'Wine', 'sku' => 'WINE', 'category' => 'FINISHED', 'unit' => 'bottles']);
        $this->forgetTenant();

        Sanctum::actingAs($admin);
        $headers = $this->tenantHeader($tenant);

        $this->putJson("/api/v1/customers/{$customer->getKey()}/product-overrides/{$item->getKey()}", [
            'visible' => false,
        ], $headers)
            ->assertOk()
            ->assertJsonPath('data.visible', false)
            ->assertJsonPath('data.inventory_item_id', $item->getKey());

        $this->getJson("/api/v1/customers/{$customer->getKey()}/product-overrides", $headers)
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.visible', false);

        // Upsert is idempotent on (customer, item).
        $this->putJson("/api/v1/customers/{$customer->getKey()}/product-overrides/{$item->getKey()}", [
            'visible' => true,
        ], $headers)->assertOk();
        $this->assertDatabaseCount('customer_product_overrides', 1);

        $this->deleteJson("/api/v1/customers/{$customer->getKey()}/product-overrides/{$item->getKey()}", [], $headers)
            ->assertNoContent();
        $this->assertDatabaseCount('customer_product_overrides', 0);
    }

    public function test_vat_lookup_returns_parsed_company_from_vies(): void
    {
        Http::fake([
            'ec.europa.eu/*' => Http::response([
                'isValid' => true,
                'name' => 'Vinarija Test d.o.o.',
                'address' => "Ilica 1\n10000 Zagreb",
            ], 200),
        ]);

        $tenant = $this->createTenant();
        $admin = $this->createMember($tenant, [TenantRole::Admin]);
        Sanctum::actingAs($admin);

        $this->getJson('/api/v1/customers/lookup-vat?vat=12345678901', $this->tenantHeader($tenant))
            ->assertOk()
            ->assertJsonPath('data.country', 'HR')
            ->assertJsonPath('data.vat', 'HR12345678901')
            ->assertJsonPath('data.name', 'Vinarija Test d.o.o.')
            ->assertJsonPath('data.zip', '10000')
            ->assertJsonPath('data.city', 'Zagreb');
    }

    public function test_vat_lookup_reports_invalid_number(): void
    {
        Http::fake([
            'ec.europa.eu/*' => Http::response(['isValid' => false], 200),
        ]);

        $tenant = $this->createTenant();
        $admin = $this->createMember($tenant, [TenantRole::Admin]);
        Sanctum::actingAs($admin);

        $this->getJson('/api/v1/customers/lookup-vat?vat=HR99999999999', $this->tenantHeader($tenant))
            ->assertStatus(422);
    }
}
