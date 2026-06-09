<?php

declare(strict_types=1);

namespace Tests\Feature\Suppliers;

use App\Enums\TenantRole;
use App\Models\InventoryItem;
use App\Models\Supplier;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\InteractsWithTenancy;
use Tests\TestCase;

class SupplierTest extends TestCase
{
    use InteractsWithTenancy;
    use RefreshDatabase;

    private Tenant $tenant;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = $this->createTenant();
        $this->admin = $this->createMember($this->tenant, [TenantRole::Admin]);
        Sanctum::actingAs($this->admin);
    }

    /** @return array<string, string> */
    private function headers(): array
    {
        return $this->tenantHeader($this->tenant);
    }

    public function test_supplier_crud_and_oib_unique_per_tenant(): void
    {
        $id = $this->postJson('/api/v1/suppliers', [
            'company_name' => 'Staklo d.o.o.', 'tax_id' => '11111111111', 'email' => 'sales@staklo.hr',
        ], $this->headers())->assertCreated()->json('data.id');

        // OIB must be unique within the tenant.
        $this->postJson('/api/v1/suppliers', [
            'company_name' => 'Dup', 'tax_id' => '11111111111',
        ], $this->headers())->assertStatus(422);

        $this->patchJson("/api/v1/suppliers/{$id}", ['payment_terms' => 'Net 30'], $this->headers())
            ->assertOk()
            ->assertJsonPath('data.payment_terms', 'Net 30');

        $this->getJson('/api/v1/suppliers?search=staklo', $this->headers())
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $id);
    }

    public function test_price_list_upsert_is_keyed_on_description(): void
    {
        $this->actingAsTenant($this->tenant);
        $supplier = Supplier::create(['company_name' => 'Staklo']);
        $cork = InventoryItem::create(['name' => 'Cork', 'sku' => 'CORK', 'category' => 'RAW_MATERIAL', 'unit' => 'units']);
        $this->forgetTenant();

        $this->postJson("/api/v1/suppliers/{$supplier->getKey()}/price-items", [
            'description' => 'Natural cork 44mm', 'unit_price' => 25, 'unit' => 'units', 'inventory_item_id' => $cork->getKey(),
        ], $this->headers())->assertCreated()->assertJsonPath('data.unit_price.minor', 25);

        // Same description → updates in place (no duplicate row).
        $this->postJson("/api/v1/suppliers/{$supplier->getKey()}/price-items", [
            'description' => 'Natural cork 44mm', 'unit_price' => 30,
        ], $this->headers())->assertCreated()->assertJsonPath('data.unit_price.minor', 30);

        $this->assertDatabaseCount('supplier_price_items', 1);

        $this->getJson("/api/v1/suppliers/{$supplier->getKey()}", $this->headers())
            ->assertOk()
            ->assertJsonCount(1, 'data.price_items')
            ->assertJsonPath('data.price_items.0.unit_price.minor', 30);
    }
}
