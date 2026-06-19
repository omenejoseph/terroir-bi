<?php

declare(strict_types=1);

namespace Tests\Feature\Orders;

use App\Enums\OrderStatus;
use App\Enums\TenantRole;
use App\Models\Customer;
use App\Models\InventoryItem;
use App\Models\Order;
use App\Models\Tenant;
use App\Queries\DemandForecastQuery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\InteractsWithTenancy;
use Tests\TestCase;

/**
 * Locks the demand-forecast maths. "Now" is frozen and orders are placed on
 * exact dates so every window, projection and rollup has a known answer.
 */
class DemandForecastTest extends TestCase
{
    use InteractsWithTenancy;
    use RefreshDatabase;

    private Tenant $tenant;

    private string $adminId;

    private Customer $customer;

    private InventoryItem $wine;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-06-15 12:00:00');

        $this->tenant = $this->createTenant();
        $this->adminId = $this->createMember($this->tenant, [TenantRole::Admin])->getKey();
        $this->actingAsTenant($this->tenant);

        $this->customer = Customer::create(['company_name' => 'Acme', 'email' => 'acme@example.com']);
        $this->wine = InventoryItem::create([
            'name' => 'Plavac 2024', 'sku' => 'PLV', 'category' => 'FINISHED', 'unit' => 'bottles',
            'sales_unit' => 'bottles', 'unit_size' => '750ml', 'group' => 'Wine', 'subcategory' => 'RED',
            'current_stock' => '30.000', 'bottles_per_case' => 6, 'is_for_sale' => true, 'is_active' => true,
        ]);

        // [date, bottles, total(minor), status]
        $orders = [
            ['2025-05-20', 10, 100000, OrderStatus::Shipped],   // LY recent-3 (May)
            ['2026-05-20', 20, 200000, OrderStatus::Shipped],   // recent-3 (May) → yoy 2.0
            ['2025-06-10', 3, 30000, OrderStatus::Shipped],     // LY same month (June)
            ['2026-06-10', 5, 50000, OrderStatus::Received],    // current month (open)
            ['2025-07-15', 2, 20000, OrderStatus::Shipped],     // LY July
        ];
        foreach ($orders as $i => [$date, $bottles, $total, $status]) {
            $this->order($date, $bottles, $total, $status, $i + 1);
        }
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function order(string $date, int $bottles, int $total, OrderStatus $status, int $n): void
    {
        $order = Order::create([
            'order_number' => "ORD-{$n}",
            'status' => $status,
            'total_amount' => $total,
            'customer_id' => $this->customer->getKey(),
            'created_by_id' => $this->adminId,
            'is_consignment' => false,
        ]);
        $order->items()->create([
            'inventory_item_id' => $this->wine->getKey(),
            'quantity' => $bottles,
            'unit_type' => 'bottles',
            'unit_price' => (int) ($total / $bottles),
            'total' => $total,
        ]);
        $order->created_at = Carbon::parse("{$date} 09:00:00");
        $order->save();
    }

    public function test_window_totals_and_yoy(): void
    {
        $d = app(DemandForecastQuery::class)->get();

        // Last 12 months: ORD-2 (2026-05), ORD-4 (2026-06), ORD-5 (2025-07). The
        // 2025-05/06 orders fall just outside the 12-month cutoff (2025-06-15).
        $this->assertSame(3, $d['totals']['last12m']['order_count']);
        $this->assertSame(1, $d['totals']['last12m']['customers']);
        $this->assertSame(27, $d['totals']['last12m']['bottles']);
        $this->assertSame(270000, $d['totals']['last12m']['revenue']['minor']);

        // Recent 3 months (200000 this year) ÷ same 3 months last year (100000).
        $this->assertSame(2.0, $d['yoy_factor']);
    }

    public function test_current_month_and_revenue_forecast(): void
    {
        $d = app(DemandForecastQuery::class)->get();

        $this->assertSame(50000, $d['current_month']['revenue_so_far']['minor']);
        $this->assertSame(30000, $d['current_month']['last_year_same_month']['minor']);
        $this->assertSame(60000, $d['current_month']['projected_full_month']['minor']); // 30000 × 2.0

        $forecast = $d['revenue_forecast_next_3m'];
        $this->assertSame(60000, $forecast[0]['expected']['minor']); // Jun: LY 30000 × 2
        $this->assertSame(40000, $forecast[1]['expected']['minor']); // Jul: LY 20000 × 2
        $this->assertNull($forecast[2]['expected']);                 // Aug: no LY → no invented number
    }

    public function test_annual_projection(): void
    {
        $annual = app(DemandForecastQuery::class)->get()['annual_revenue_projection'];

        $this->assertSame(2026, $annual['year']);
        $this->assertSame(250000, $annual['ytd']['minor']);              // 2026-05 + 2026-06
        $this->assertSame(50000, $annual['projected_remaining']['minor']); // Jun gap 10000 + Jul 40000
        $this->assertSame(300000, $annual['projected_total']['minor']);
        $this->assertTrue($annual['has_history_gap']);                   // Aug–Dec have no LY
    }

    public function test_product_row_and_sell_through(): void
    {
        $products = app(DemandForecastQuery::class)->get()['products'];
        $this->assertCount(1, $products);
        $p = $products[0];

        $this->assertSame(27, $p['last12m']['bottles']);
        $this->assertSame(25, $p['annual_projection']['ytd_bottles']); // 20 + 5 in 2026
        $this->assertSame(35, $p['sold']);        // shipped: 10 + 20 + 3 + 2
        $this->assertSame(5, $p['to_be_sold']);   // open (RECEIVED): 5
        $this->assertSame(30, $p['remaining']);   // current_stock, in bottles
        $this->assertSame(70, $p['total_produced']); // no bottlings → 30 + 35 + 5 fallback

        // Biggest month over the trailing 12 (2025-07…2026-06): 2026-05 with 20.
        $this->assertSame('2026-05', $p['biggest_month']['month']);
        $this->assertSame(20, $p['biggest_month']['bottles']);

        // 12-month sparkline has a point per month, newest last.
        $this->assertCount(12, $p['history_12m']);
        $this->assertSame('2026-06', $p['history_12m'][11]['month']);
        $this->assertSame(5, $p['history_12m'][11]['bottles']);
    }

    public function test_customer_cadence(): void
    {
        $customers = app(DemandForecastQuery::class)->get()['customers'];
        $this->assertCount(1, $customers);
        $c = $customers[0];

        $this->assertSame(5, $c['order_count']);
        $this->assertSame(270000, $c['revenue_last_12m']['minor']);
        $this->assertSame(250000, $c['revenue_ytd']['minor']);
        // Gaps (days): 21, 35, 309, 21 → median (21+35)/2 = 28.
        $this->assertSame(28, $c['median_gap_days']);
        $this->assertSame(5, $c['days_since_last_order']);       // 2026-06-10 → 2026-06-15
        $this->assertSame('2026-07-08', substr((string) $c['expected_by_date'], 0, 10)); // last + 28d
    }

    public function test_category_breakdown_rolls_up_subcategories(): void
    {
        $categories = app(DemandForecastQuery::class)->get()['category_breakdown'];
        $this->assertCount(1, $categories);

        $wine = $categories[0];
        $this->assertSame('Wine', $wine['group']);
        $this->assertSame(27, $wine['last12m_bottles']);
        $this->assertCount(1, $wine['subcategories']);
        $this->assertSame('RED', $wine['subcategories'][0]['subcategory']);
        $this->assertSame(27, $wine['subcategories'][0]['last12m_bottles']);
    }

    public function test_excluded_customer_orders_are_ignored(): void
    {
        $internal = Customer::create([
            'company_name' => 'Internal', 'email' => 'in@example.com', 'exclude_from_stats' => true,
        ]);
        $o = Order::create([
            'order_number' => 'ORD-INT', 'status' => OrderStatus::Shipped, 'total_amount' => 999000,
            'customer_id' => $internal->getKey(), 'created_by_id' => $this->adminId, 'is_consignment' => false,
        ]);
        $o->items()->create(['inventory_item_id' => $this->wine->getKey(), 'quantity' => 99, 'unit_type' => 'bottles', 'unit_price' => 10000, 'total' => 999000]);
        $o->created_at = Carbon::parse('2026-06-12 09:00:00');
        $o->save();

        $d = app(DemandForecastQuery::class)->get();
        // Unchanged from the base fixture — the internal order is excluded.
        $this->assertSame(270000, $d['totals']['last12m']['revenue']['minor']);
        $this->assertCount(1, $d['customers']);
    }
}
