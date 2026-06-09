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

class OrderAnalyticsTest extends TestCase
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
            'current_stock' => '500.000', 'bottles_per_case' => 12, 'is_for_sale' => true,
            'default_price' => 1000, 'cost_per_unit' => 400,
        ]);
        $this->forgetTenant();
    }

    /** @return array<string, string> */
    private function headers(): array
    {
        return $this->tenantHeader($this->tenant);
    }

    private function createOrder(int $quantity, string $unitType, bool $consignment = false): string
    {
        Sanctum::actingAs($this->admin);

        return $this->postJson('/api/v1/orders', [
            'customer_id' => $this->customer->getKey(),
            'is_consignment' => $consignment,
            'items' => [['inventory_item_id' => $this->wine->getKey(), 'quantity' => $quantity, 'unit_type' => $unitType]],
        ], $this->headers())->assertCreated()->json('data.id');
    }

    public function test_order_analytics_reports_revenue_cogs_and_margin(): void
    {
        $this->createOrder(2, 'cases'); // rev 24000, cogs 9600 → profit 14400, margin 60%

        Sanctum::actingAs($this->admin);
        $this->getJson('/api/v1/orders/analytics?period=all', $this->headers())
            ->assertOk()
            ->assertJsonPath('data.revenue.minor', 24000)
            ->assertJsonPath('data.cogs.minor', 9600)
            ->assertJsonPath('data.gross_profit.minor', 14400)
            ->assertJsonPath('data.margin_percent', '60.00')
            ->assertJsonPath('data.order_count', 1)
            ->assertJsonPath('data.top_products.0.name', 'Plavac')
            ->assertJsonPath('data.top_products.0.revenue.minor', 24000);
    }

    public function test_consignment_sell_through_is_reported_separately(): void
    {
        $id = $this->createOrder(2, 'cases', consignment: true);
        $itemId = Order::query()->whereKey($id)->firstOrFail()->items()->firstOrFail()->getKey();

        $this->postJson("/api/v1/orders/{$id}/consignment/sale", [
            'items' => [['order_item_id' => $itemId, 'quantity' => 10]], // 10 × 1000
        ], $this->headers())->assertOk();

        $this->getJson('/api/v1/orders/analytics?period=all', $this->headers())
            ->assertOk()
            ->assertJsonPath('data.order_count', 0)              // consignment excluded from core P&L
            ->assertJsonPath('data.revenue.minor', 0)
            ->assertJsonPath('data.consignment_revenue.minor', 10000);
    }

    public function test_customer_insights(): void
    {
        $this->createOrder(2, 'cases'); // 24000
        $this->createOrder(1, 'bottles'); // 1000 → total 25000 across 2 orders

        Sanctum::actingAs($this->admin);
        $this->getJson("/api/v1/customers/{$this->customer->getKey()}/insights", $this->headers())
            ->assertOk()
            ->assertJsonPath('data.total_spend.minor', 25000)
            ->assertJsonPath('data.order_count', 2)
            ->assertJsonPath('data.avg_order_value.minor', 12500)
            ->assertJsonPath('data.top_products.0.inventory_item_id', $this->wine->getKey());
    }
}
