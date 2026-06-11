<?php

declare(strict_types=1);

namespace Tests\Feature\Suppliers;

use App\Enums\TenantRole;
use App\Models\Supplier;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\InteractsWithTenancy;
use Tests\TestCase;

class SupplierPortalTest extends TestCase
{
    use InteractsWithTenancy;
    use RefreshDatabase;

    private Tenant $tenant;

    private User $admin;

    private Supplier $supplier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = $this->createTenant();
        $this->admin = $this->createMember($this->tenant, [TenantRole::Admin]);

        $this->actingAsTenant($this->tenant);
        $this->supplier = Supplier::create(['company_name' => 'Serrano and Crawford Inc', 'contact_name' => 'Hiram Richards']);
        $this->forgetTenant();
    }

    /** @return array<string, string> */
    private function headers(): array
    {
        return $this->tenantHeader($this->tenant);
    }

    private function enablePortal(string $token = 'tok_portal_123'): string
    {
        $this->actingAsTenant($this->tenant);
        $this->supplier->update(['portal_token' => $token]);
        $this->forgetTenant();

        return $token;
    }

    // ─── Admin token management ───

    public function test_admin_generates_and_revokes_portal_token(): void
    {
        Sanctum::actingAs($this->admin);

        $res = $this->postJson("/api/v1/suppliers/{$this->supplier->getKey()}/portal-token", [], $this->headers())
            ->assertOk()
            ->assertJsonPath('data.has_portal_token', true);
        $token = $res->json('data.portal_token');
        $this->assertIsString($token);
        $this->assertNotEmpty($token);

        $this->deleteJson("/api/v1/suppliers/{$this->supplier->getKey()}/portal-token", [], $this->headers())
            ->assertOk()
            ->assertJsonPath('data.has_portal_token', false);
    }

    public function test_token_management_requires_suppliers_manage(): void
    {
        $member = $this->createMember($this->tenant, [TenantRole::WineClub]);
        Sanctum::actingAs($member);

        $this->postJson("/api/v1/suppliers/{$this->supplier->getKey()}/portal-token", [], $this->headers())
            ->assertForbidden();
    }

    // ─── Public portal ───

    public function test_public_portal_returns_supplier_open_orders_and_price_list(): void
    {
        $token = $this->enablePortal();

        $this->actingAsTenant($this->tenant);
        $this->supplier->priceItems()->create(['description' => 'Natural cork 44mm', 'unit_price' => 2500, 'unit' => 'units']);
        $this->supplier->orders()->create(['order_number' => 'PO-1', 'status' => 'SENT', 'created_by_id' => $this->admin->getKey()]);
        $this->supplier->orders()->create(['order_number' => 'PO-DRAFT', 'status' => 'DRAFT', 'created_by_id' => $this->admin->getKey()]);
        $this->forgetTenant();

        $this->getJson("/api/v1/public/supplier/{$token}")
            ->assertOk()
            ->assertJsonPath('data.supplier.company_name', 'Serrano and Crawford Inc')
            ->assertJsonCount(1, 'data.orders') // only the open (SENT) one
            ->assertJsonPath('data.orders.0.order_number', 'PO-1')
            ->assertJsonCount(1, 'data.price_items')
            ->assertJsonPath('data.price_items.0.description', 'Natural cork 44mm');
    }

    public function test_public_portal_rejects_an_unknown_token(): void
    {
        $this->getJson('/api/v1/public/supplier/nope')->assertNotFound();
    }

    public function test_public_import_upserts_price_items_by_description(): void
    {
        $token = $this->enablePortal();

        $this->actingAsTenant($this->tenant);
        $this->supplier->priceItems()->create(['description' => 'Cork', 'unit_price' => 100]);
        $this->forgetTenant();

        $this->postJson("/api/v1/public/supplier/{$token}/price-items/import", [
            'items' => [
                ['description' => 'Cork', 'unit_price' => 250, 'unit' => 'units'], // updates existing
                ['description' => 'Capsule', 'unit_price' => 80],
                ['description' => 'Label', 'unit_price' => 40],
            ],
        ])
            ->assertOk()
            ->assertJsonPath('data.added', 2)
            ->assertJsonPath('data.updated', 1)
            ->assertJsonPath('data.total', 3);

        $this->assertDatabaseCount('supplier_price_items', 3);
    }

    public function test_public_confirm_advances_a_sent_order(): void
    {
        $token = $this->enablePortal();

        $this->actingAsTenant($this->tenant);
        $po = $this->supplier->orders()->create(['order_number' => 'PO-9', 'status' => 'SENT', 'created_by_id' => $this->admin->getKey()]);
        $this->forgetTenant();

        $this->patchJson("/api/v1/public/supplier/{$token}/orders/{$po->getKey()}/confirm")
            ->assertOk()
            ->assertJsonPath('data.status', 'CONFIRMED');
    }

    public function test_public_confirm_rejects_a_foreign_order_and_non_sent(): void
    {
        $token = $this->enablePortal();

        $this->actingAsTenant($this->tenant);
        $other = Supplier::create(['company_name' => 'Other']);
        $foreign = $other->orders()->create(['order_number' => 'PO-X', 'status' => 'SENT', 'created_by_id' => $this->admin->getKey()]);
        $draft = $this->supplier->orders()->create(['order_number' => 'PO-D', 'status' => 'DRAFT', 'created_by_id' => $this->admin->getKey()]);
        $this->forgetTenant();

        $this->patchJson("/api/v1/public/supplier/{$token}/orders/{$foreign->getKey()}/confirm")->assertNotFound();
        $this->patchJson("/api/v1/public/supplier/{$token}/orders/{$draft->getKey()}/confirm")->assertStatus(422);
    }
}
