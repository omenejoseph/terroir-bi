<?php

declare(strict_types=1);

namespace Tests\Feature\Suppliers;

use App\Enums\TenantRole;
use App\Models\InventoryItem;
use App\Models\Supplier;
use App\Models\SupplierOrder;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\InteractsWithTenancy;
use Tests\TestCase;

class SupplierOrderTest extends TestCase
{
    use InteractsWithTenancy;
    use RefreshDatabase;

    private Tenant $tenant;

    private User $admin;

    private Supplier $supplier;

    private InventoryItem $cork;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = $this->createTenant();
        $this->admin = $this->createMember($this->tenant, [TenantRole::Admin]);
        $this->actingAsTenant($this->tenant);
        $this->supplier = Supplier::create(['company_name' => 'Staklo']);
        $this->cork = InventoryItem::create([
            'name' => 'Cork', 'sku' => 'CORK', 'category' => 'RAW_MATERIAL', 'unit' => 'units',
            'current_stock' => '100.000', 'cost_per_unit' => 20,
        ]);
        $this->forgetTenant();
        Sanctum::actingAs($this->admin);
    }

    /** @return array<string, string> */
    private function headers(): array
    {
        return $this->tenantHeader($this->tenant);
    }

    public function test_receiving_a_po_books_stock_and_updates_cost_per_product(): void
    {
        $id = $this->postJson('/api/v1/supplier-orders', [
            'supplier_id' => $this->supplier->getKey(),
            'items' => [[
                'inventory_item_id' => $this->cork->getKey(), 'description' => 'Cork 44mm',
                'quantity' => 500, 'unit' => 'units', 'unit_price' => 25, // new landed cost 0.25
            ]],
        ], $this->headers())
            ->assertCreated()
            ->assertJsonPath('data.status', 'DRAFT')
            ->assertJsonPath('data.total_amount.minor', 12500) // 500 × 25
            ->json('data.id');

        // Receiving the goods adds stock and refreshes cost_per_unit (landed cost).
        $this->patchJson("/api/v1/supplier-orders/{$id}/status", ['status' => 'RECEIVED'], $this->headers())
            ->assertOk()
            ->assertJsonPath('data.status', 'RECEIVED')
            ->assertJsonPath('data.received_at', fn ($v) => $v !== null);

        $cork = $this->cork->refresh();
        $this->assertSame('600.000', (string) $cork->current_stock); // 100 + 500
        $this->assertSame(25, $cork->cost_per_unit?->getMinorAmount()); // updated from 20 → 25
        $this->assertDatabaseHas('stock_movements', [
            'inventory_item_id' => $cork->getKey(), 'type' => 'PURCHASE_IN', 'reference' => 'PO-00001',
        ]);
    }

    public function test_receiving_is_applied_once(): void
    {
        $id = $this->postJson('/api/v1/supplier-orders', [
            'supplier_id' => $this->supplier->getKey(),
            'items' => [['inventory_item_id' => $this->cork->getKey(), 'description' => 'Cork', 'quantity' => 10, 'unit_price' => 25]],
        ], $this->headers())->assertCreated()->json('data.id');

        $this->patchJson("/api/v1/supplier-orders/{$id}/status", ['status' => 'RECEIVED'], $this->headers())->assertOk();
        // Re-sending RECEIVED must not double-book stock.
        $this->patchJson("/api/v1/supplier-orders/{$id}/status", ['status' => 'RECEIVED'], $this->headers())->assertOk();

        $this->assertSame('110.000', (string) $this->cork->refresh()->current_stock);
        $this->assertSame(1, $this->cork->stockMovements()->where('type', 'PURCHASE_IN')->count());
    }

    public function test_received_po_cannot_be_deleted(): void
    {
        $this->actingAsTenant($this->tenant);
        $po = SupplierOrder::create([
            'order_number' => 'PO-09999', 'supplier_id' => $this->supplier->getKey(),
            'created_by_id' => $this->admin->getKey(), 'status' => 'RECEIVED',
        ]);
        $this->forgetTenant();

        $this->deleteJson("/api/v1/supplier-orders/{$po->getKey()}", [], $this->headers())->assertStatus(422);
    }
}
