<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Enums\TenantRole;
use App\Models\Customer;
use App\Models\InventoryItem;
use App\Models\Order;
use App\Models\StockMovement;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\InteractsWithTenancy;
use Tests\TestCase;

class InventoryItemStockAnalyticsTest extends TestCase
{
    use InteractsWithTenancy;
    use RefreshDatabase;

    private Tenant $tenant;

    private User $admin;

    private InventoryItem $item;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-06-15 12:00:00');
        $this->tenant = $this->createTenant();
        $this->admin = $this->createMember($this->tenant, [TenantRole::Admin]);
        $this->actingAsTenant($this->tenant);

        // Bottles-unit wine: 150 in stock, min 50, list €29.99/bottle, no cost set.
        $this->item = InventoryItem::create([
            'name' => 'Plavac', 'sku' => 'PLV', 'category' => 'FINISHED', 'unit' => 'bottles',
            'sales_unit' => 'bottles', 'bottles_per_case' => 12,
            'current_stock' => '150.000', 'min_stock' => '50', 'default_price' => 2999,
        ]);

        // Realized sales: one bottle line of 150 @ €19.99 (revenue €2998.50).
        $customer = Customer::create(['company_name' => 'Konoba', 'email' => 'k@example.com']);
        $order = Order::create([
            'order_number' => 'ORD-1', 'customer_id' => $customer->getKey(),
            'created_by_id' => $this->admin->getKey(), 'total_amount' => 299850,
        ]);
        $order->forceFill(['created_at' => Carbon::parse('2026-06-10')])->save();
        $order->items()->create([
            'inventory_item_id' => $this->item->getKey(), 'quantity' => 150,
            'unit_type' => 'bottles', 'unit_price' => 1999, 'total' => 299850, 'cost_per_unit' => null,
        ]);

        // Warehouse exits this period (sales 100, manual 20, production 10) + one out of window.
        $this->exit('ORDER_DEDUCT', -100, '2026-06-12');
        $this->exit('MANUAL_OUT', -20, '2026-06-12');
        $this->exit('PRODUCTION_OUT', -10, '2026-06-12');
        $this->exit('MANUAL_OUT', -500, '2026-03-01'); // ~106 days ago — outside 30d

        $this->forgetTenant();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function exit(string $type, int $qty, string $at): void
    {
        $m = StockMovement::create([
            'inventory_item_id' => $this->item->getKey(), 'type' => $type, 'quantity' => $qty,
        ]);
        $m->forceFill(['created_at' => Carbon::parse($at)])->save();
    }

    /** @return array<string, string> */
    private function headers(): array
    {
        return $this->tenantHeader($this->tenant);
    }

    public function test_current_and_realized_metrics(): void
    {
        Sanctum::actingAs($this->admin);
        $this->getJson("/api/v1/inventory-items/{$this->item->getKey()}/stock-analytics?period=30d", $this->headers())
            ->assertOk()
            ->assertJsonPath('data.current.stock_bottles', 150)
            ->assertJsonPath('data.current.min_stock_bottles', 50)
            ->assertJsonPath('data.current.cost_per_bottle', null)
            ->assertJsonPath('data.current.selling_per_bottle.minor', 2999)
            ->assertJsonPath('data.realized.mean_price.minor', 1999)
            ->assertJsonPath('data.realized.rebate_amount.minor', 1000)
            ->assertJsonPath('data.realized.rebate_percent', '33.3')
            ->assertJsonPath('data.realized.margin_percent', '100.0')
            ->assertJsonPath('data.realized.sales_value.minor', 299850); // 150 × 19.99
    }

    public function test_exits_and_channels_for_the_period(): void
    {
        Sanctum::actingAs($this->admin);
        $this->getJson("/api/v1/inventory-items/{$this->item->getKey()}/stock-analytics?period=30d", $this->headers())
            ->assertOk()
            ->assertJsonPath('data.exits.bottles_exited', 130) // 100 + 20 + 10 (the −500 is out of window)
            ->assertJsonPath('data.exits.cost_of_exits', null)  // no cost set
            ->assertJsonPath('data.exits.revenue_realized.minor', 299850)
            ->assertJsonPath('data.exits.mean_margin_percent', '100.0')
            ->assertJsonPath('data.exits.days_of_stock_left', 35) // round(150 × 30 / 130)
            ->assertJsonCount(3, 'data.channels')
            ->assertJsonFragment(['channel' => 'sales', 'bottles' => 100])
            ->assertJsonFragment(['channel' => 'manual', 'bottles' => 20])
            ->assertJsonFragment(['channel' => 'production', 'bottles' => 10]);
    }

    public function test_today_period_has_no_exits(): void
    {
        Sanctum::actingAs($this->admin);
        // All movements are days ago, so "today" shows nothing exited.
        $this->getJson("/api/v1/inventory-items/{$this->item->getKey()}/stock-analytics?period=today", $this->headers())
            ->assertOk()
            ->assertJsonPath('data.exits.bottles_exited', 0)
            ->assertJsonPath('data.exits.revenue_realized', null)
            ->assertJsonPath('data.exits.days_of_stock_left', null)
            ->assertJsonPath('data.channels', []);
    }

    public function test_requires_inventory_visibility(): void
    {
        $member = $this->createMember($this->tenant, [TenantRole::WineClub]);
        Sanctum::actingAs($member);
        $this->getJson("/api/v1/inventory-items/{$this->item->getKey()}/stock-analytics", $this->headers())
            ->assertForbidden();
    }
}
