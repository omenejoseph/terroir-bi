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

class InventorySpendTest extends TestCase
{
    use InteractsWithTenancy;
    use RefreshDatabase;

    private Tenant $tenant;

    private User $admin;

    private InventoryItem $wine;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-06-10 12:00:00');
        $this->tenant = $this->createTenant();
        $this->admin = $this->createMember($this->tenant, [TenantRole::Admin]);

        $this->actingAsTenant($this->tenant);
        $this->wine = InventoryItem::create([
            'name' => 'Premium Red Blend', 'sku' => 'FP-REDWINE-001', 'category' => 'FINISHED',
            'unit' => 'bottles', 'group' => 'Wine', 'subcategory' => 'Red Wine', 'vintage' => 2024,
            'current_stock' => '150', 'cost_per_unit' => 400,
        ]);
        // A raw material exit that must NOT count toward finished-product spend.
        $raw = InventoryItem::create([
            'name' => 'Cork', 'sku' => 'CRK', 'category' => 'RAW_MATERIAL', 'unit' => 'unit', 'current_stock' => '5000',
        ]);
        $this->exit($raw, -50, '2026-06-05');
        $this->forgetTenant();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function exit(InventoryItem $item, int $qty, string $at): void
    {
        $m = StockMovement::create([
            'inventory_item_id' => $item->getKey(), 'type' => 'MANUAL_OUT', 'quantity' => $qty,
        ]);
        $m->forceFill(['created_at' => Carbon::parse($at)])->save();
    }

    /** @return array<string, string> */
    private function headers(): array
    {
        return $this->tenantHeader($this->tenant);
    }

    public function test_spend_for_a_window(): void
    {
        $this->actingAsTenant($this->tenant);
        // Two exits this window (Jun 1–10): 40 + 20 = 60 bottles over 2 movements.
        $this->exit($this->wine, -40, '2026-06-03');
        $this->exit($this->wine, -20, '2026-06-07');
        // One exit in the PRIOR window (Jun 1 − 10d window → prev May 22–31): 10 bottles.
        $this->exit($this->wine, -10, '2026-05-25');

        // A realized sale this window.
        $customer = Customer::create(['company_name' => 'Konoba', 'email' => 'k@example.com']);
        $order = Order::create([
            'order_number' => 'ORD-1', 'customer_id' => $customer->getKey(),
            'created_by_id' => $this->admin->getKey(), 'total_amount' => 120000,
        ]);
        $order->forceFill(['created_at' => Carbon::parse('2026-06-03')])->save();
        $order->items()->create([
            'inventory_item_id' => $this->wine->getKey(), 'quantity' => 60,
            'unit_type' => 'bottles', 'unit_price' => 2000, 'total' => 120000, 'cost_per_unit' => 400,
        ]);
        $this->forgetTenant();

        Sanctum::actingAs($this->admin);
        $res = $this->getJson('/api/v1/inventory-items/spend?from=2026-06-01&to=2026-06-10', $this->headers())
            ->assertOk();

        // Summary (finished only — the raw cork exit is excluded).
        $res->assertJsonPath('data.summary.units_exited', 60)
            ->assertJsonPath('data.summary.movements', 2)
            ->assertJsonPath('data.summary.cost_value.minor', 24000)   // 60 × €4.00
            ->assertJsonPath('data.summary.revenue.minor', 120000)
            ->assertJsonPath('data.summary.distinct_skus', 1)
            // Previous equal-length window saw the 10-bottle exit.
            ->assertJsonPath('data.previous.units_exited', 10);

        // Daily series spans the 10-day window.
        $res->assertJsonCount(10, 'data.daily');

        // Per-product: the finished wine, with on-hand + velocity + days-left.
        $res->assertJsonPath('data.per_product.0.sku', 'FP-REDWINE-001')
            ->assertJsonPath('data.per_product.0.on_hand', 150)
            ->assertJsonPath('data.per_product.0.units_exited', 60)
            ->assertJsonPath('data.per_product.0.prev_units_exited', 10)
            ->assertJsonPath('data.per_product.0.days_left', 25); // round(150 / (60/10))
        $this->assertCount(10, $res->json('data.per_product.0.daily')); // sparkline buckets
    }

    public function test_empty_window_is_zeroed(): void
    {
        Sanctum::actingAs($this->admin);
        $this->getJson('/api/v1/inventory-items/spend?from=2030-01-01&to=2030-01-10', $this->headers())
            ->assertOk()
            ->assertJsonPath('data.summary.units_exited', 0)
            ->assertJsonPath('data.summary.distinct_skus', 0)
            ->assertJsonPath('data.per_product.0.units_exited', 0)
            ->assertJsonPath('data.per_product.0.days_left', null);
    }

    public function test_spend_requires_inventory_visibility(): void
    {
        $member = $this->createMember($this->tenant, [TenantRole::WineClub]);
        Sanctum::actingAs($member);
        $this->getJson('/api/v1/inventory-items/spend', $this->headers())->assertForbidden();
    }
}
