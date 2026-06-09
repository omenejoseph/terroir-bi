<?php

declare(strict_types=1);

namespace Tests\Feature\Orders;

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

class ConsignmentTest extends TestCase
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
        $this->customer = Customer::create(['company_name' => 'Bar', 'email' => 'b@example.com']);
        $this->wine = InventoryItem::create([
            'name' => 'Plavac', 'sku' => 'PLV', 'category' => 'FINISHED', 'unit' => 'bottles',
            'sales_unit' => 'cases',
            'current_stock' => '100.000', 'bottles_per_case' => 12, 'is_for_sale' => true,
            'default_price' => 12000, 'cost_per_unit' => 4800, // per case (sales_unit=cases)
        ]);
        $this->forgetTenant();
    }

    /** @return array{0:string,1:string} order id + first order_item id */
    private function placeConsignment(): array
    {
        Sanctum::actingAs($this->admin);
        $id = $this->postJson('/api/v1/orders', [
            'customer_id' => $this->customer->getKey(),
            'is_consignment' => true,
            'items' => [['inventory_item_id' => $this->wine->getKey(), 'quantity' => 2, 'unit_type' => 'cases']],
        ], $this->tenantHeader($this->tenant))->assertCreated()->json('data.id');

        $itemId = Order::query()->whereKey((string) $id)->firstOrFail()->items()->firstOrFail()->getKey();

        return [$id, $itemId];
    }

    public function test_placement_deducts_stock_and_summary_starts_outstanding(): void
    {
        [$id] = $this->placeConsignment();
        $this->assertSame('76.000', (string) $this->wine->refresh()->current_stock); // 24 placed

        $this->getJson("/api/v1/orders/{$id}/consignment", $this->tenantHeader($this->tenant))
            ->assertOk()
            ->assertJsonPath('data.totals.placed', 24)
            ->assertJsonPath('data.totals.sold', 0)
            ->assertJsonPath('data.totals.remaining', 24)
            ->assertJsonPath('data.lines.0.per_bottle_price.minor', 1000);
    }

    public function test_sale_recognizes_revenue_and_cogs_without_touching_stock(): void
    {
        [$id, $itemId] = $this->placeConsignment();

        $this->postJson("/api/v1/orders/{$id}/consignment/sale", [
            'items' => [['order_item_id' => $itemId, 'quantity' => 10]],
        ], $this->tenantHeader($this->tenant))
            ->assertOk()
            ->assertJsonPath('data.totals.sold', 10)
            ->assertJsonPath('data.totals.remaining', 14)
            ->assertJsonPath('data.totals.revenue.minor', 10000) // 10 × 1000
            ->assertJsonPath('data.totals.cogs.minor', 4000)     // 10 × 400
            ->assertJsonPath('data.totals.profit.minor', 6000);

        $this->assertSame('76.000', (string) $this->wine->refresh()->current_stock); // unchanged
    }

    public function test_return_restocks(): void
    {
        [$id, $itemId] = $this->placeConsignment();

        $this->postJson("/api/v1/orders/{$id}/consignment/return", [
            'items' => [['order_item_id' => $itemId, 'quantity' => 5]],
        ], $this->tenantHeader($this->tenant))
            ->assertOk()
            ->assertJsonPath('data.totals.returned', 5)
            ->assertJsonPath('data.totals.remaining', 19);

        $this->assertSame('81.000', (string) $this->wine->refresh()->current_stock); // 76 + 5
    }

    public function test_sale_cannot_exceed_outstanding(): void
    {
        [$id, $itemId] = $this->placeConsignment();

        $this->postJson("/api/v1/orders/{$id}/consignment/sale", [
            'items' => [['order_item_id' => $itemId, 'quantity' => 999]],
        ], $this->tenantHeader($this->tenant))->assertStatus(422);
    }

    public function test_close_auto_returns_remainder_and_stamps_closed(): void
    {
        [$id, $itemId] = $this->placeConsignment();

        $this->postJson("/api/v1/orders/{$id}/consignment/sale", [
            'items' => [['order_item_id' => $itemId, 'quantity' => 10]],
        ], $this->tenantHeader($this->tenant))->assertOk();

        $this->postJson("/api/v1/orders/{$id}/consignment/close", [], $this->tenantHeader($this->tenant))
            ->assertOk()
            ->assertJsonPath('data.totals.remaining', 0);

        // 76 + 14 remainder returned = 90.
        $this->assertSame('90.000', (string) $this->wine->refresh()->current_stock);
        $this->assertNotNull(Order::query()->whereKey((string) $id)->firstOrFail()->consignment_closed_at);
    }

    public function test_consignment_actions_require_a_consignment_order(): void
    {
        Sanctum::actingAs($this->admin);
        $id = $this->postJson('/api/v1/orders', [
            'customer_id' => $this->customer->getKey(),
            'items' => [['inventory_item_id' => $this->wine->getKey(), 'quantity' => 1, 'unit_type' => 'cases']],
        ], $this->tenantHeader($this->tenant))->assertCreated()->json('data.id');
        $itemId = Order::query()->whereKey((string) $id)->firstOrFail()->items()->firstOrFail()->getKey();

        $this->postJson("/api/v1/orders/{$id}/consignment/sale", [
            'items' => [['order_item_id' => $itemId, 'quantity' => 1]],
        ], $this->tenantHeader($this->tenant))->assertStatus(422);
    }
}
