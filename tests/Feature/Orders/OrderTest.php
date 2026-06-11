<?php

declare(strict_types=1);

namespace Tests\Feature\Orders;

use App\DataTransferObjects\OrderData;
use App\Enums\TenantRole;
use App\Models\Customer;
use App\Models\InventoryItem;
use App\Models\Order;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\InteractsWithTenancy;
use Tests\TestCase;

class OrderTest extends TestCase
{
    use InteractsWithTenancy;
    use RefreshDatabase;

    private Tenant $tenant;

    private User $admin;

    private Customer $customer;

    private InventoryItem $wine;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = $this->createTenant();
        $this->admin = $this->createMember($this->tenant, [TenantRole::Admin]);
        $this->actingAsTenant($this->tenant);
        $this->customer = Customer::create(['company_name' => 'Konoba', 'email' => 'k@example.com']);
        $this->wine = InventoryItem::create([
            'name' => 'Plavac', 'sku' => 'PLV', 'category' => 'FINISHED', 'unit' => 'bottles',
            'sales_unit' => 'cases',
            'current_stock' => '100.000', 'bottles_per_case' => 12, 'is_for_sale' => true,
            'default_price' => 1000, 'cost_per_unit' => 400,
        ]);
        $this->forgetTenant();
    }

    /** @return array<string, string> */
    private function headers(): array
    {
        return $this->tenantHeader($this->tenant);
    }

    private function createOrderViaApi(int $quantity = 2, string $unitType = 'cases'): string
    {
        Sanctum::actingAs($this->admin);

        return $this->postJson('/api/v1/orders', [
            'customer_id' => $this->customer->getKey(),
            'items' => [['inventory_item_id' => $this->wine->getKey(), 'quantity' => $quantity, 'unit_type' => $unitType]],
        ], $this->headers())->assertCreated()->json('data.id');
    }

    public function test_create_order_resolves_price_snapshots_cogs_and_deducts_stock(): void
    {
        Sanctum::actingAs($this->admin);

        $this->postJson('/api/v1/orders', [
            'customer_id' => $this->customer->getKey(),
            'items' => [['inventory_item_id' => $this->wine->getKey(), 'quantity' => 2, 'unit_type' => 'cases']],
        ], $this->headers())
            ->assertCreated()
            ->assertJsonPath('data.status', 'RECEIVED')
            ->assertJsonPath('data.items.0.unit_price.minor', 12000)   // 10.00/bottle × 12
            ->assertJsonPath('data.items.0.total.minor', 24000)
            ->assertJsonPath('data.items.0.cost_per_unit.minor', 4800) // 4.00/bottle × 12
            ->assertJsonPath('data.total_amount.minor', 24000)
            ->assertJsonCount(1, 'data.status_history');

        $this->assertSame('76.000', (string) $this->wine->refresh()->current_stock);
    }

    public function test_catalog_item_can_only_be_ordered_in_its_sales_unit(): void
    {
        Sanctum::actingAs($this->admin);

        // wine.sales_unit = cases — ordering it in bottles is rejected.
        $this->postJson('/api/v1/orders', [
            'customer_id' => $this->customer->getKey(),
            'items' => [['inventory_item_id' => $this->wine->getKey(), 'quantity' => 1, 'unit_type' => 'bottles']],
        ], $this->headers())
            ->assertStatus(422)
            ->assertJsonValidationErrors(['items']);
    }

    public function test_create_order_is_blocked_when_stock_is_short(): void
    {
        $this->actingAsTenant($this->tenant);
        $this->wine->update(['current_stock' => '5.000']);
        $this->forgetTenant();

        Sanctum::actingAs($this->admin);
        $this->postJson('/api/v1/orders', [
            'customer_id' => $this->customer->getKey(),
            'items' => [['inventory_item_id' => $this->wine->getKey(), 'quantity' => 1, 'unit_type' => 'cases']],
        ], $this->headers())->assertStatus(422);

        $this->assertSame('5.000', (string) $this->wine->refresh()->current_stock);
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_backorder_does_not_deduct_stock(): void
    {
        Sanctum::actingAs($this->admin);
        $this->postJson('/api/v1/orders', [
            'customer_id' => $this->customer->getKey(),
            'is_backorder' => true,
            'backorder_date' => '2026-07-01',
            'items' => [['inventory_item_id' => $this->wine->getKey(), 'quantity' => 5, 'unit_type' => 'cases']],
        ], $this->headers())->assertCreated()->assertJsonPath('data.is_backorder', true);

        $this->assertSame('100.000', (string) $this->wine->refresh()->current_stock);
    }

    public function test_backorder_deducts_stock_when_opted_in(): void
    {
        Sanctum::actingAs($this->admin);
        $this->postJson('/api/v1/orders', [
            'customer_id' => $this->customer->getKey(),
            'is_backorder' => true,
            'backorder_date' => '2026-07-01',
            'deduct_stock' => true,
            'items' => [['inventory_item_id' => $this->wine->getKey(), 'quantity' => 5, 'unit_type' => 'cases']],
        ], $this->headers())
            ->assertCreated()
            ->assertJsonPath('data.is_backorder', true)
            ->assertJsonPath('data.deduct_stock', true);

        // 100 cases - (5 cases × 12 bottles) = 100 - 60 ... wine stocked in bottles.
        $this->assertSame('40.000', (string) $this->wine->refresh()->current_stock);
    }

    public function test_status_transition_appends_history(): void
    {
        $id = $this->createOrderViaApi();

        $this->patchJson("/api/v1/orders/{$id}/status", ['status' => 'IN_PROCESS', 'note' => 'Picking'], $this->headers())
            ->assertOk()
            ->assertJsonPath('data.status', 'IN_PROCESS')
            ->assertJsonCount(2, 'data.status_history');
    }

    public function test_editing_quantity_adjusts_stock_and_total(): void
    {
        $id = $this->createOrderViaApi(2, 'cases'); // 24 deducted → 76
        $itemId = Order::findOrFail($id)->items()->firstOrFail()->getKey();

        $this->patchJson("/api/v1/order-items/{$itemId}", ['quantity' => 1], $this->headers())
            ->assertOk()
            ->assertJsonPath('data.items.0.total.minor', 12000)
            ->assertJsonPath('data.total_amount.minor', 12000);

        // 100 - 12 = 88 after reversing the original 24 and deducting 12.
        $this->assertSame('88.000', (string) $this->wine->refresh()->current_stock);
    }

    public function test_overriding_line_cost(): void
    {
        $id = $this->createOrderViaApi();
        $itemId = Order::findOrFail($id)->items()->firstOrFail()->getKey();

        $this->patchJson("/api/v1/order-items/{$itemId}/cost", ['cost_per_unit' => 9999], $this->headers())
            ->assertOk()
            ->assertJsonPath('data.items.0.cost_per_unit.minor', 9999);
    }

    public function test_deleting_a_line_restocks_and_last_line_is_protected(): void
    {
        $id = $this->createOrderViaApi();
        $order = Order::findOrFail($id);
        $firstItem = $order->items()->firstOrFail()->getKey();

        // Only one line → cannot delete.
        $this->deleteJson("/api/v1/order-items/{$firstItem}", [], $this->headers())->assertStatus(422);

        // Add a second line, then delete it → restock.
        $this->postJson("/api/v1/orders/{$id}/items", [
            'items' => [['inventory_item_id' => $this->wine->getKey(), 'quantity' => 1, 'unit_type' => 'cases']],
        ], $this->headers())->assertOk();
        $this->assertSame('64.000', (string) $this->wine->refresh()->current_stock); // 76 - 12 (1 case)

        $secondItem = $order->items()->latest('id')->firstOrFail()->getKey();
        $this->deleteJson("/api/v1/order-items/{$secondItem}", [], $this->headers())->assertOk();
        $this->assertSame('76.000', (string) $this->wine->refresh()->current_stock); // restored
    }

    public function test_deleting_an_order_restocks_everything(): void
    {
        $id = $this->createOrderViaApi(); // 24 deducted → 76

        $this->deleteJson("/api/v1/orders/{$id}", [], $this->headers())->assertNoContent();

        $this->assertSame('100.000', (string) $this->wine->refresh()->current_stock);
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_setting_shipping_cost(): void
    {
        $id = $this->createOrderViaApi();

        $this->patchJson("/api/v1/orders/{$id}/shipping", ['shipping_cost' => 1500], $this->headers())
            ->assertOk()
            ->assertJsonPath('data.shipping_cost.minor', 1500)
            ->assertJsonPath('data.shipping_paid_by_us', true);
    }

    public function test_edit_window_blocks_late_edits_for_restricted_members(): void
    {
        $id = $this->createOrderViaApi();
        $order = Order::findOrFail($id);
        $itemId = $order->items()->firstOrFail()->getKey();
        // Age the order beyond the 1-hour window.
        $order->forceFill(['created_at' => now()->subHours(2)])->save();

        $teamMember = $this->createMember($this->tenant, [TenantRole::Team]); // can_edit_orders=false
        Sanctum::actingAs($teamMember);
        $this->patchJson("/api/v1/order-items/{$itemId}", ['quantity' => 1], $this->headers())->assertStatus(403);

        // Admin is exempt.
        Sanctum::actingAs($this->admin);
        $this->patchJson("/api/v1/order-items/{$itemId}", ['quantity' => 1], $this->headers())->assertOk();
    }

    public function test_shipped_orders_are_hidden_from_members_without_visibility(): void
    {
        $open = $this->createOrderViaApi();
        $shipped = $this->createOrderViaApi(1, 'cases');
        $this->patchJson("/api/v1/orders/{$shipped}/status", ['status' => 'SHIPPED'], $this->headers())->assertOk();

        $teamMember = $this->createMember($this->tenant, [TenantRole::Team]); // can_see_shipped_orders=false
        Sanctum::actingAs($teamMember);
        $this->getJson('/api/v1/orders', $this->headers())
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $open);
    }

    public function test_cogs_is_hidden_when_financials_not_visible(): void
    {
        $id = $this->createOrderViaApi();
        $order = Order::findOrFail($id)->load('items');

        $withFinancials = OrderData::fromModel($order, true)->toArray();
        $withoutFinancials = OrderData::fromModel($order, false)->toArray();

        $this->assertArrayHasKey('cost_per_unit', $withFinancials['items'][0]);
        $this->assertArrayNotHasKey('cost_per_unit', $withoutFinancials['items'][0]);
    }

    public function test_customer_with_orders_is_deactivated_not_deleted(): void
    {
        $id = $this->createOrderViaApi();
        Sanctum::actingAs($this->admin);

        $this->deleteJson("/api/v1/customers/{$this->customer->getKey()}", [], $this->headers())
            ->assertOk()
            ->assertJsonPath('data.is_active', false);

        $this->assertDatabaseHas('customers', ['id' => $this->customer->getKey(), 'is_active' => false]);
        unset($id);
    }
}
