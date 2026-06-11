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

class InventoryAnalyticsTest extends TestCase
{
    use InteractsWithTenancy;
    use RefreshDatabase;

    private Tenant $tenant;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-06-15 12:00:00');
        $this->tenant = $this->createTenant();
        $this->admin = $this->createMember($this->tenant, [TenantRole::Admin]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function exit(InventoryItem $item, string $type, int $qty, string $at): void
    {
        $m = StockMovement::create([
            'inventory_item_id' => $item->getKey(), 'type' => $type, 'quantity' => $qty,
        ]);
        $m->forceFill(['created_at' => Carbon::parse($at)])->save();
    }

    /** @return array<string, string> */
    private function headers(): array
    {
        return $this->tenantHeader($this->tenant);
    }

    public function test_analytics_payload(): void
    {
        $this->actingAsTenant($this->tenant);

        // 1 finished (priced, in stock), 1 semi, 4 raw (one out of stock, one below min).
        $wine = InventoryItem::create([
            'name' => 'Premium Red Blend', 'sku' => 'PRB', 'category' => 'FINISHED', 'unit' => 'bottles',
            'group' => 'Wine', 'current_stock' => '150', 'min_stock' => '50',
            'default_price' => 2999, 'is_for_sale' => true,
        ]);
        InventoryItem::create([
            'name' => 'Base wine', 'sku' => 'BW', 'category' => 'SEMI_FINISHED', 'unit' => 'liter',
            'group' => 'Wine', 'current_stock' => '500',
        ]);
        InventoryItem::create(['name' => 'Cork', 'sku' => 'CRK', 'category' => 'RAW_MATERIAL', 'unit' => 'unit', 'group' => 'Packaging', 'current_stock' => '0', 'min_stock' => '100']);
        InventoryItem::create(['name' => 'Label', 'sku' => 'LBL', 'category' => 'RAW_MATERIAL', 'unit' => 'unit', 'group' => 'Packaging', 'current_stock' => '8000']);
        InventoryItem::create(['name' => 'Bottle', 'sku' => 'BTL', 'category' => 'RAW_MATERIAL', 'unit' => 'unit', 'group' => 'Packaging', 'current_stock' => '5000']);
        InventoryItem::create(['name' => 'Capsule', 'sku' => 'CAP', 'category' => 'RAW_MATERIAL', 'unit' => 'unit', 'group' => 'Packaging', 'current_stock' => '200']);

        // A sale of 100 bottles @ €19.99 (list €29.99) within 90d, no cost → margin 100%.
        $customer = Customer::create(['company_name' => 'Konoba', 'email' => 'k@example.com']);
        $order = Order::create([
            'order_number' => 'ORD-1', 'customer_id' => $customer->getKey(),
            'created_by_id' => $this->admin->getKey(), 'total_amount' => 199900,
        ]);
        $order->forceFill(['created_at' => Carbon::parse('2026-06-10')])->save();
        $order->items()->create([
            'inventory_item_id' => $wine->getKey(), 'quantity' => 100,
            'unit_type' => 'bottles', 'unit_price' => 1999, 'total' => 199900, 'cost_per_unit' => null,
        ]);

        // Warehouse exits: 100 via sales, 20 manual, within 90d; plus an old one out of window.
        $this->exit($wine, 'ORDER_DEDUCT', -100, '2026-06-10');
        $this->exit($wine, 'MANUAL_OUT', -20, '2026-06-10');
        $this->exit($wine, 'MANUAL_OUT', -999, '2026-01-01'); // >90d ago
        // A movement last month for the 12-month series.
        $this->exit($wine, 'MANUAL_OUT', -10, '2026-05-15');

        $this->forgetTenant();

        Sanctum::actingAs($this->admin);
        $res = $this->getJson('/api/v1/inventory-items/analytics', $this->headers())->assertOk();

        // Summary.
        $res->assertJsonPath('data.summary.total_active', 6)
            ->assertJsonPath('data.summary.low_stock', 1)        // Cork (0 < 100)
            ->assertJsonPath('data.summary.out_of_stock', 1)     // Cork
            ->assertJsonPath('data.summary.for_sale', 1)
            ->assertJsonPath('data.summary.by_category.FINISHED', 1)
            ->assertJsonPath('data.summary.by_category.SEMI_FINISHED', 1)
            ->assertJsonPath('data.summary.by_category.RAW_MATERIAL', 4)
            ->assertJsonPath('data.summary.sale_value.minor', 449850) // 150 × 29.99
            ->assertJsonPath('data.summary.production_value.minor', 0)
            ->assertJsonPath('data.summary.margin_percent', '100');

        // Warehouse exit portfolio.
        $res->assertJsonPath('data.portfolio_exits.external.units_exited', 100)
            ->assertJsonPath('data.portfolio_exits.external.revenue_realized.minor', 199900)
            ->assertJsonPath('data.portfolio_exits.external.mean_price.minor', 1999)
            ->assertJsonPath('data.portfolio_exits.external.off_target_percent', '33.3') // (29.99-19.99)/29.99
            ->assertJsonPath('data.portfolio_exits.blended.units_exited', 130) // 100 + 20 + 10 (old excluded)
            ->assertJsonPath('data.portfolio_exits.blended.revenue_realized.minor', 199900);

        // 12-month series has 12 buckets ending this month.
        $res->assertJsonCount(12, 'data.movements_12m')
            ->assertJsonPath('data.movements_12m.11.month', '2026-06')
            ->assertJsonPath('data.movements_12m.11.out', 120); // 100 + 20 in June

        // Top products + by group.
        $res->assertJsonPath('data.top_products.0.name', 'Premium Red Blend')
            ->assertJsonPath('data.top_products.0.value', 449850)
            ->assertJsonFragment(['group' => 'Packaging', 'count' => 4])
            ->assertJsonFragment(['group' => 'Wine', 'count' => 2]);
    }

    public function test_analytics_requires_inventory_visibility(): void
    {
        $member = $this->createMember($this->tenant, [TenantRole::WineClub]);
        Sanctum::actingAs($member);
        $this->getJson('/api/v1/inventory-items/analytics', $this->headers())->assertForbidden();
    }
}
