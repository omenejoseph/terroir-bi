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
            'sales_unit' => 'cases',
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
            ->assertJsonPath('data.top_products.data.0.name', 'Plavac')
            ->assertJsonPath('data.top_products.data.0.revenue.minor', 24000);
    }

    public function test_analytics_extended_sections_numbers(): void
    {
        $this->createOrder(2, 'cases'); // rev 24000, cogs 9600, profit 14400, margin 60%, 24 bottles

        Sanctum::actingAs($this->admin);
        $res = $this->getJson('/api/v1/orders/analytics?period=all', $this->headers())->assertOk();

        $res->assertJsonPath('data.bottles_sold', 24)
            ->assertJsonPath('data.total_lines', 1)
            ->assertJsonPath('data.avg_margin_pct_per_order', '60.00')
            ->assertJsonPath('data.items_with_unknown_cost', 0)
            ->assertJsonPath('data.revenue_without_cost.minor', 0);

        // Previous period (nothing before) → zeroed.
        $res->assertJsonPath('data.previous.revenue.minor', 0)
            ->assertJsonPath('data.previous.order_count', 0);

        // Paginated rankings keep correct meta + summary.
        $res->assertJsonPath('data.top_customers.meta.total', 1)
            ->assertJsonPath('data.top_customers.meta.current_page', 1)
            ->assertJsonPath('data.top_customers.data.0.company_name', 'Konoba')
            ->assertJsonPath('data.top_customers.data.0.gross_profit.minor', 14400)
            ->assertJsonPath('data.top_customers.data.0.orders_count', 1)
            ->assertJsonPath('data.top_products.meta.total', 1)
            ->assertJsonPath('data.top_products.data.0.quantity', 24);

        // Channel: Konoba defaults to the Wholesale customer type.
        $res->assertJsonPath('data.channels.0.key', 'wholesale')
            ->assertJsonPath('data.channels.0.revenue.minor', 24000)
            ->assertJsonPath('data.channels.0.revenue_share_pct', '100.0')
            ->assertJsonPath('data.channels.0.bottles', 24);

        // Price realization: sold at list (1000/btl × 24 = 24000) → no leakage.
        $res->assertJsonPath('data.price_realization.totals.list_revenue.minor', 24000)
            ->assertJsonPath('data.price_realization.totals.realized_revenue.minor', 24000)
            ->assertJsonPath('data.price_realization.totals.leakage.minor', 0)
            ->assertJsonPath('data.price_realization.totals.realization_pct', '100.0');

        // Margin histogram: a 60% order lands in the "50%+" bucket.
        $histogram = $res->json('data.histogram');
        $top = collect(is_array($histogram) ? $histogram : [])->firstWhere('label', '50%+');
        $this->assertSame(1, $top['orders']);
    }

    public function test_lines_without_cost_are_surfaced(): void
    {
        Sanctum::actingAs($this->admin);
        $this->actingAsTenant($this->tenant);
        // A new release with a price but no cost recorded yet.
        $uncosted = InventoryItem::create([
            'name' => 'New Release', 'sku' => 'NEW', 'category' => 'FINISHED', 'unit' => 'bottles',
            'sales_unit' => 'bottles', 'current_stock' => '100.000', 'bottles_per_case' => 6,
            'is_for_sale' => true, 'default_price' => 2000, // cost_per_unit omitted
        ]);
        $this->postJson('/api/v1/orders', [
            'customer_id' => $this->customer->getKey(),
            'is_consignment' => false,
            'items' => [['inventory_item_id' => $uncosted->getKey(), 'quantity' => 6, 'unit_type' => 'bottles']],
        ], $this->headers())->assertCreated();

        $this->getJson('/api/v1/orders/analytics?period=all', $this->headers())
            ->assertOk()
            ->assertJsonPath('data.items_with_unknown_cost', 1)
            ->assertJsonPath('data.lines_without_cost.0.product_name', 'New Release')
            ->assertJsonPath('data.lines_without_cost.0.line_revenue.minor', 12000) // 2000 × 6
            ->assertJsonPath('data.revenue_without_cost.minor', 12000);
    }

    public function test_low_margin_order_is_flagged(): void
    {
        $id = $this->createOrder(1, 'cases'); // rev 12000
        $itemId = Order::query()->whereKey($id)->firstOrFail()->items()->firstOrFail()->getKey();
        // Bump the snapshot cost so the order's margin drops under 20%.
        $this->patchJson("/api/v1/order-items/{$itemId}/cost", ['cost_per_unit' => 11000], $this->headers())->assertOk();

        Sanctum::actingAs($this->admin);
        $this->getJson('/api/v1/orders/analytics?period=all', $this->headers())
            ->assertOk()
            ->assertJsonPath('data.low_margin_orders.0.order_number', Order::query()->whereKey($id)->value('order_number'));
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
        $this->createOrder(1, 'cases'); // 12000 → total 36000 across 2 orders

        Sanctum::actingAs($this->admin);
        $this->getJson("/api/v1/customers/{$this->customer->getKey()}/insights", $this->headers())
            ->assertOk()
            ->assertJsonPath('data.total_spend.minor', 36000)
            ->assertJsonPath('data.order_count', 2)
            ->assertJsonPath('data.avg_order_value.minor', 18000)
            ->assertJsonPath('data.top_products.0.inventory_item_id', $this->wine->getKey());
    }
}
