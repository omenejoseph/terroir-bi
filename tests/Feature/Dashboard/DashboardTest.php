<?php

declare(strict_types=1);

namespace Tests\Feature\Dashboard;

use App\Enums\CostStatus;
use App\Enums\InflowStatus;
use App\Enums\OrderStatus;
use App\Enums\TenantRole;
use App\Models\Cost;
use App\Models\Customer;
use App\Models\Inflow;
use App\Models\InventoryItem;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\WorkOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\InteractsWithTenancy;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use InteractsWithTenancy;
    use RefreshDatabase;

    public function test_summary_returns_real_stats_and_placeholder_series(): void
    {
        $tenant = $this->createTenant();
        $admin = $this->createMember($tenant, [TenantRole::Admin]);
        $this->actingAsTenant($tenant);
        InventoryItem::create([
            'name' => 'Cork', 'sku' => 'C', 'category' => 'RAW_MATERIAL', 'unit' => 'units',
            'current_stock' => '5', 'min_stock' => '20',
        ]);
        $this->forgetTenant();

        Sanctum::actingAs($admin);

        $this->getJson('/api/v1/dashboard?range=30D', $this->tenantHeader($tenant))
            ->assertOk()
            ->assertJsonPath('data.range', '30D')
            ->assertJsonPath('data.stats.low_stock', 1)   // real
            ->assertJsonPath('data.stats.customers', 0)   // real (none created)
            ->assertJsonStructure([
                'data' => [
                    'currency',
                    'stats' => ['total_orders', 'customers', 'revenue', 'low_stock'],
                    'orders' => [['label', 'value']],
                    'revenue' => [['label', 'value']],
                    'order_status' => [['key', 'value']],
                    'stock_watch',
                    'recent_orders',
                ],
            ]);
    }

    public function test_summary_returns_revenue_summary_and_resolves_period(): void
    {
        $tenant = $this->createTenant();
        $admin = $this->createMember($tenant, [TenantRole::Admin]);

        Sanctum::actingAs($admin);

        $this->getJson('/api/v1/dashboard?period=mtd', $this->tenantHeader($tenant))
            ->assertOk()
            ->assertJsonPath('data.range', 'mtd')
            ->assertJsonStructure([
                'data' => [
                    'revenue_summary' => [
                        'today' => ['current', 'previous'],
                        'mtd' => ['current', 'previous'],
                        'ytd' => ['current', 'previous'],
                        'total' => ['current'],
                    ],
                ],
            ])
            ->assertJsonPath('data.revenue_summary.total.previous', null);
    }

    public function test_revenue_summary_year_over_year_comparisons_populate(): void
    {
        $tenant = $this->createTenant();
        $admin = $this->createMember($tenant, [TenantRole::Admin]);
        $this->actingAsTenant($tenant);
        $customer = Customer::create(['company_name' => 'Co', 'email' => 'co@example.com', 'customer_type' => 'WHOLESALE']);

        // Today (this year) and exactly one year ago today. The latter falls inside
        // last year's today / month-to-date / year-to-date windows, so all three
        // comparisons resolve (instead of "—").
        Order::create(['order_number' => 'TY', 'status' => OrderStatus::Received->value, 'total_amount' => 16000, 'customer_id' => $customer->getKey(), 'created_by_id' => $admin->getKey(), 'is_consignment' => false]);
        $ly = Order::create(['order_number' => 'LY', 'status' => OrderStatus::Received->value, 'total_amount' => 20000, 'customer_id' => $customer->getKey(), 'created_by_id' => $admin->getKey(), 'is_consignment' => false]);
        $ly->created_at = now()->subYear();
        $ly->save();
        $this->forgetTenant();

        Sanctum::actingAs($admin);
        $this->getJson('/api/v1/dashboard?period=mtd', $this->tenantHeader($tenant))
            ->assertOk()
            ->assertJsonPath('data.revenue_summary.today.current', 16000)
            ->assertJsonPath('data.revenue_summary.today.previous', 20000)
            ->assertJsonPath('data.revenue_summary.mtd.current', 16000)
            ->assertJsonPath('data.revenue_summary.mtd.previous', 20000)
            ->assertJsonPath('data.revenue_summary.ytd.current', 16000)
            ->assertJsonPath('data.revenue_summary.ytd.previous', 20000);
    }

    public function test_summary_splits_revenue_by_customer_channel(): void
    {
        $tenant = $this->createTenant();
        $admin = $this->createMember($tenant, [TenantRole::Admin]);
        $this->actingAsTenant($tenant);
        $wholesale = Customer::create(['company_name' => 'Big Distributor', 'email' => 'w@example.com', 'customer_type' => 'WHOLESALE']);
        $retail = Customer::create(['company_name' => 'Corner Shop', 'email' => 'r@example.com', 'customer_type' => 'RETAIL']);
        $wine = InventoryItem::create([
            'name' => 'Plavac', 'sku' => 'PLV', 'category' => 'FINISHED', 'unit' => 'bottles',
            'sales_unit' => 'cases', 'current_stock' => '500.000', 'bottles_per_case' => 12,
            'is_for_sale' => true, 'default_price' => 1000,
        ]);
        $this->forgetTenant();

        Sanctum::actingAs($admin);
        $headers = $this->tenantHeader($tenant);

        // Wholesale: 2 cases = 24000. Retail: 1 case = 12000.
        $this->postJson('/api/v1/orders', [
            'customer_id' => $wholesale->getKey(),
            'items' => [['inventory_item_id' => $wine->getKey(), 'quantity' => 2, 'unit_type' => 'cases']],
        ], $headers)->assertCreated();
        $this->postJson('/api/v1/orders', [
            'customer_id' => $retail->getKey(),
            'items' => [['inventory_item_id' => $wine->getKey(), 'quantity' => 1, 'unit_type' => 'cases']],
        ], $headers)->assertCreated();

        $this->getJson('/api/v1/dashboard?period=ytd', $headers)
            ->assertOk()
            ->assertJsonPath('data.revenue_by_channel.wholesale.current', 24000)
            ->assertJsonPath('data.revenue_by_channel.retail.current', 12000)
            ->assertJsonPath('data.revenue_by_channel.agency.current', 0)
            ->assertJsonPath('data.revenue_by_channel.total.current', 36000)
            // Key ratios: DTC = retail/total (12000/36000), AOV = wholesale/order count (24000/2).
            ->assertJsonPath('data.key_ratios.dtc_revenue_pct', 33.3)
            ->assertJsonPath('data.key_ratios.avg_order_value.minor', 12000);
    }

    public function test_channel_trend_and_cost_ratios_populate(): void
    {
        $tenant = $this->createTenant();
        $admin = $this->createMember($tenant, [TenantRole::Admin]);
        $this->actingAsTenant($tenant);
        $wholesale = Customer::create(['company_name' => 'Distributor', 'email' => 'd@example.com', 'customer_type' => 'WHOLESALE']);

        // Current-window order (now) + prior-window order (~45 days ago) → trend.
        Order::create(['order_number' => 'O-CUR', 'status' => OrderStatus::Received->value, 'total_amount' => 24000, 'customer_id' => $wholesale->getKey(), 'created_by_id' => $admin->getKey(), 'is_consignment' => false]);
        $prev = Order::create(['order_number' => 'O-PREV', 'status' => OrderStatus::Received->value, 'total_amount' => 10000, 'customer_id' => $wholesale->getKey(), 'created_by_id' => $admin->getKey(), 'is_consignment' => false]);
        $prev->created_at = now()->subDays(45);
        $prev->save();

        // Costs in the current window → cost ratios.
        Cost::create(['category' => 'salary', 'description' => 'Payroll', 'total_amount' => 5000, 'date' => now(), 'status' => CostStatus::Paid->value, 'created_by_id' => $admin->getKey()]);
        Cost::create(['category' => 'marketing', 'description' => 'Ads', 'total_amount' => 2000, 'date' => now(), 'status' => CostStatus::Paid->value, 'created_by_id' => $admin->getKey()]);
        $this->forgetTenant();

        Sanctum::actingAs($admin);
        $this->getJson('/api/v1/dashboard?range=30D', $this->tenantHeader($tenant))
            ->assertOk()
            // Trend: 24000 in the current 30d window, 10000 in the prior 30d window.
            ->assertJsonPath('data.revenue_by_channel.wholesale.current', 24000)
            ->assertJsonPath('data.revenue_by_channel.wholesale.previous', 10000)
            // Cost ratios over current revenue (24000): salary 5000 → 20.8%, marketing 2000 → 8.3%.
            ->assertJsonPath('data.key_ratios.employee_cost_pct', 20.8)
            ->assertJsonPath('data.key_ratios.marketing_cost_pct', 8.3)
            ->assertJsonPath('data.key_ratios.revenue_per_employee.minor', 24000); // 1 distinct salary line
    }

    public function test_key_ratios_are_computed_correctly_end_to_end(): void
    {
        $tenant = $this->createTenant();
        $admin = $this->createMember($tenant, [TenantRole::Admin]);
        $this->actingAsTenant($tenant);

        $wholesale = Customer::create(['company_name' => 'Distributor', 'email' => 'w@example.com', 'customer_type' => 'WHOLESALE']);
        $retail = Customer::create(['company_name' => 'Shop', 'email' => 'r@example.com', 'customer_type' => 'RETAIL']);
        $wine = InventoryItem::create([
            'name' => 'Wine', 'sku' => 'W', 'category' => 'FINISHED', 'unit' => 'bottles',
            'bottles_per_case' => 6, 'current_stock' => '100.000', 'cost_per_unit' => 500,
            'default_price' => 1000, 'is_for_sale' => true, 'is_active' => true,
        ]);

        // Two shipped orders this period: wholesale 20,000 (20 btl) + retail 10,000 (10 btl).
        foreach ([[$wholesale, 'WO', 20000, 20], [$retail, 'RO', 10000, 10]] as [$customer, $no, $total, $qty]) {
            $order = Order::create(['order_number' => $no, 'status' => OrderStatus::Shipped->value, 'total_amount' => $total, 'customer_id' => $customer->getKey(), 'created_by_id' => $admin->getKey(), 'is_consignment' => false]);
            OrderItem::create(['order_id' => $order->getKey(), 'inventory_item_id' => $wine->getKey(), 'quantity' => $qty, 'unit_type' => 'bottles', 'unit_price' => 1000, 'total' => $total, 'cost_per_unit' => 500]);
        }

        // Costs this period: salary 4,000 + marketing 2,000 + operations 500 = 6,500.
        Cost::create(['category' => 'Salary', 'description' => 'Payroll', 'total_amount' => 4000, 'date' => now(), 'status' => CostStatus::Paid->value, 'created_by_id' => $admin->getKey()]);
        Cost::create(['category' => 'Marketing', 'description' => 'Ads', 'total_amount' => 2000, 'date' => now(), 'status' => CostStatus::Paid->value, 'created_by_id' => $admin->getKey()]);
        Cost::create(['category' => 'Operations', 'description' => 'Misc', 'total_amount' => 500, 'date' => now(), 'status' => CostStatus::Paid->value, 'created_by_id' => $admin->getKey()]);
        $this->forgetTenant();

        Sanctum::actingAs($admin);
        $this->getJson('/api/v1/dashboard?range=30D', $this->tenantHeader($tenant))
            ->assertOk()
            // revenue 30,000 · DTC = retail 10,000 → 33.3%
            ->assertJsonPath('data.key_ratios.dtc_revenue_pct', 33.3)
            // operating margin = (30,000 − 6,500) / 30,000 → 78.3%
            ->assertJsonPath('data.key_ratios.operating_margin_pct', 78.3)
            // employee 4,000 / 30,000 → 13.3% · marketing 2,000 / 30,000 → 6.7%
            ->assertJsonPath('data.key_ratios.employee_cost_pct', 13.3)
            ->assertJsonPath('data.key_ratios.marketing_cost_pct', 6.7)
            // COGS = 500×20 + 500×10 = 15,000 → 50% of revenue
            ->assertJsonPath('data.key_ratios.cogs_pct', 50)
            ->assertJsonPath('data.key_ratios.cogs_amount.minor', 15000)
            // headcount = 1 salary line → revenue / 1 = 30,000
            ->assertJsonPath('data.key_ratios.revenue_per_employee.minor', 30000)
            // wholesale revenue 20,000 / 2 orders = 10,000
            ->assertJsonPath('data.key_ratios.avg_order_value.minor', 10000)
            // bottles sold 30 / stock on hand 100 → 0.3×
            ->assertJsonPath('data.key_ratios.inventory_turnover', 0.3);
    }

    public function test_top_products_sums_line_revenue(): void
    {
        $tenant = $this->createTenant();
        $admin = $this->createMember($tenant, [TenantRole::Admin]);
        $this->actingAsTenant($tenant);
        $customer = Customer::create(['company_name' => 'Co', 'email' => 'c@example.com', 'customer_type' => 'WHOLESALE']);
        $item = InventoryItem::create(['name' => 'Plavac', 'sku' => 'PLV', 'category' => 'FINISHED', 'unit' => 'bottles', 'current_stock' => '50.000']);

        // Two order lines of the same item: 7,000 + 5,000 = 12,000 of revenue.
        foreach ([7000, 5000] as $i => $total) {
            $order = Order::create(['order_number' => "TP{$i}", 'status' => OrderStatus::Received->value, 'total_amount' => $total, 'customer_id' => $customer->getKey(), 'created_by_id' => $admin->getKey(), 'is_consignment' => false]);
            OrderItem::create(['order_id' => $order->getKey(), 'inventory_item_id' => $item->getKey(), 'quantity' => 10, 'unit_type' => 'bottles', 'unit_price' => 700, 'total' => $total]);
        }
        $this->forgetTenant();

        Sanctum::actingAs($admin);
        $this->getJson('/api/v1/dashboard?range=30D', $this->tenantHeader($tenant))
            ->assertOk()
            ->assertJsonPath('data.top_products.0.name', 'Plavac')
            ->assertJsonPath('data.top_products.0.value', 12000);
    }

    public function test_orders_and_revenue_series_sum_to_totals(): void
    {
        $tenant = $this->createTenant();
        $admin = $this->createMember($tenant, [TenantRole::Admin]);
        $this->actingAsTenant($tenant);
        $customer = Customer::create(['company_name' => 'Co', 'email' => 'c@example.com', 'customer_type' => 'WHOLESALE']);

        // Three orders inside the 30-day window (well clear of the bucket edges).
        foreach ([[0, 10000], [3, 6000], [7, 4000]] as [$days, $total]) {
            $order = Order::create(['order_number' => "S{$days}", 'status' => OrderStatus::Received->value, 'total_amount' => $total, 'customer_id' => $customer->getKey(), 'created_by_id' => $admin->getKey(), 'is_consignment' => false]);
            if ($days > 0) {
                $order->created_at = now()->subDays($days);
                $order->save();
            }
        }
        $this->forgetTenant();

        Sanctum::actingAs($admin);
        $data = $this->getJson('/api/v1/dashboard?range=30D', $this->tenantHeader($tenant))->assertOk()->json('data');

        // The bucketed series must add up to the headline totals (no orders lost/double-counted).
        $this->assertSame($data['stats']['total_orders'], array_sum(array_column($data['orders'], 'value')));
        $this->assertSame($data['stats']['revenue'], array_sum(array_column($data['revenue'], 'value')));
        $this->assertSame(3, $data['stats']['total_orders']);
        $this->assertSame(20000, $data['stats']['revenue']);
    }

    public function test_summary_defaults_invalid_range(): void
    {
        $tenant = $this->createTenant();
        $admin = $this->createMember($tenant, [TenantRole::Admin]);

        Sanctum::actingAs($admin);

        $this->getJson('/api/v1/dashboard?range=bogus', $this->tenantHeader($tenant))
            ->assertOk()
            ->assertJsonPath('data.range', '30D');
    }

    /** The 8-tile ratio grid and the reorder pipeline both read off the same service other screens already trust. */
    public function test_key_ratios_and_reorder_pipeline_are_both_present(): void
    {
        $tenant = $this->createTenant();
        $admin = $this->createMember($tenant, [TenantRole::Admin]);
        $this->actingAsTenant($tenant);

        // Three orders with a 10-day median gap, last one 20 days ago → ratio
        // 2.0 → "overdue" on the radar (mirrors ReorderRadarQueryTest).
        $customer = Customer::create(['company_name' => 'Slipping Bar', 'email' => 's@example.com']);
        foreach ([40, 30, 20] as $daysAgo) {
            $order = Order::create([
                'order_number' => 'ORD-'.$daysAgo, 'status' => OrderStatus::Received->value,
                'total_amount' => 10000, 'customer_id' => $customer->getKey(),
                'created_by_id' => $admin->getKey(), 'is_consignment' => false,
            ]);
            $order->forceFill(['created_at' => now()->subDays($daysAgo)])->save();
        }
        $this->forgetTenant();

        Sanctum::actingAs($admin);
        $data = $this->getJson('/api/v1/dashboard?range=90D', $this->tenantHeader($tenant))
            ->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'key_ratios' => [
                        'dtc_revenue_pct', 'operating_margin_pct', 'employee_cost_pct',
                        'marketing_cost_pct', 'cogs_pct', 'cogs_amount', 'revenue_per_employee',
                        'avg_order_value', 'inventory_turnover',
                    ],
                    'reorder_pipeline' => ['total', 'rows'],
                ],
            ])
            ->json('data');

        $this->assertSame($customer->getKey(), $data['reorder_pipeline']['rows'][0]['customer_id']);
        $this->assertSame(10000, $data['reorder_pipeline']['total']['minor']);
    }

    /** stock_watch only lists items actually below their minimum, worst shortfall first, with a unit. */
    public function test_stock_watch_lists_only_items_below_minimum(): void
    {
        $tenant = $this->createTenant();
        $admin = $this->createMember($tenant, [TenantRole::Admin]);
        $this->actingAsTenant($tenant);
        InventoryItem::create([
            'name' => 'Barely low', 'sku' => 'BL', 'category' => 'FINISHED', 'unit' => 'bottles',
            'sales_unit' => 'bottles', 'current_stock' => '18', 'min_stock' => '20',
        ]);
        InventoryItem::create([
            'name' => 'Critically low', 'sku' => 'CL', 'category' => 'FINISHED', 'unit' => 'bottles',
            'sales_unit' => 'cases', 'current_stock' => '2', 'min_stock' => '20',
        ]);
        InventoryItem::create([
            'name' => 'Healthy', 'sku' => 'HT', 'category' => 'FINISHED', 'unit' => 'bottles',
            'sales_unit' => 'bottles', 'current_stock' => '50', 'min_stock' => '20',
        ]);
        $this->forgetTenant();

        Sanctum::actingAs($admin);
        $rows = $this->getJson('/api/v1/dashboard?range=30D', $this->tenantHeader($tenant))
            ->assertOk()
            ->json('data.stock_watch');

        $this->assertCount(2, $rows);
        // Furthest below minimum first: 2/20 (10%) before 18/20 (90%).
        $this->assertSame('Critically low', $rows[0]['name']);
        $this->assertSame('cases', $rows[0]['unit']);
        $this->assertSame('Barely low', $rows[1]['name']);
    }

    public function test_upcoming_tasks_counts_due_this_week_and_lists_soonest_first(): void
    {
        $tenant = $this->createTenant();
        $admin = $this->createMember($tenant, [TenantRole::Admin]);
        $this->actingAsTenant($tenant);
        WorkOrder::create([
            'title' => 'Overdue cellar check', 'category' => 'CELLAR', 'status' => 'TODO',
            'created_by_id' => $admin->getKey(), 'due_date' => now()->subDay(),
        ]);
        WorkOrder::create([
            'title' => 'No due date', 'status' => 'TODO', 'created_by_id' => $admin->getKey(),
        ]);
        WorkOrder::create([
            'title' => 'Next quarter', 'status' => 'TODO', 'created_by_id' => $admin->getKey(),
            'due_date' => now()->addMonths(3),
        ]);
        WorkOrder::create([
            'title' => 'Already done', 'status' => 'DONE', 'created_by_id' => $admin->getKey(),
            'due_date' => now()->subDay(),
        ]);
        $this->forgetTenant();

        Sanctum::actingAs($admin);
        $tasks = $this->getJson('/api/v1/dashboard?range=30D', $this->tenantHeader($tenant))
            ->assertOk()
            ->json('data.upcoming_tasks');

        // Only the overdue one is due by the end of this week; the done task never counts.
        $this->assertSame(1, $tasks['due_this_week']);
        $this->assertSame('Overdue cellar check', $tasks['rows'][0]['title']);
        $this->assertTrue($tasks['rows'][0]['overdue']);
    }

    public function test_net_cash_flow_nets_received_inflows_against_costs_by_category(): void
    {
        $tenant = $this->createTenant();
        $admin = $this->createMember($tenant, [TenantRole::Admin]);
        $this->actingAsTenant($tenant);
        Inflow::create([
            'date' => now(), 'amount' => 5000, 'status' => InflowStatus::Received->value,
            'created_by_id' => $admin->getKey(),
        ]);
        // Pending inflow must not count as cash in.
        Inflow::create([
            'date' => now(), 'amount' => 9000, 'status' => InflowStatus::Pending->value,
            'created_by_id' => $admin->getKey(),
        ]);
        Cost::create(['category' => 'Salary', 'description' => 'Payroll', 'total_amount' => 3000, 'date' => now(), 'status' => CostStatus::Paid->value, 'created_by_id' => $admin->getKey()]);
        Cost::create(['category' => 'Marketing', 'description' => 'Ads', 'total_amount' => 1000, 'date' => now(), 'status' => CostStatus::Paid->value, 'created_by_id' => $admin->getKey()]);
        Cost::create(['category' => 'Courier', 'description' => 'Misc', 'total_amount' => 500, 'date' => now(), 'status' => CostStatus::Paid->value, 'created_by_id' => $admin->getKey()]);
        $this->forgetTenant();

        Sanctum::actingAs($admin);
        $flow = $this->getJson('/api/v1/dashboard?range=30D', $this->tenantHeader($tenant))
            ->assertOk()
            ->json('data.net_cash_flow');

        // Cash in 5,000 − cash out 4,500 (3,000 + 1,000 + 500) = 500.
        $this->assertSame(500, $flow['net']['minor']);
        $byLabel = collect($flow['by_category'])->keyBy('label');
        $this->assertSame(3000, $byLabel['Salary']['amount']['minor']);
        $this->assertSame(1000, $byLabel['Marketing']['amount']['minor']);
        $this->assertSame(0, $byLabel['Operations']['amount']['minor']);
        // The free-text "Courier" category falls into Other rather than growing the legend.
        $this->assertSame(500, $byLabel['Other']['amount']['minor']);
    }

    public function test_summary_reflects_real_orders_ar_and_tasks(): void
    {
        $tenant = $this->createTenant();
        $admin = $this->createMember($tenant, [TenantRole::Admin]);
        $this->actingAsTenant($tenant);
        $customer = Customer::create(['company_name' => 'Konoba', 'email' => 'k@example.com']);
        $wine = InventoryItem::create([
            'name' => 'Plavac', 'sku' => 'PLV', 'category' => 'FINISHED', 'unit' => 'bottles',
            'sales_unit' => 'cases',
            'current_stock' => '500.000', 'bottles_per_case' => 12, 'is_for_sale' => true, 'default_price' => 1000,
        ]);
        WorkOrder::create(['title' => 'Overdue', 'created_by_id' => $admin->getKey(), 'due_date' => now()->subDay(), 'status' => 'TODO']);
        $this->forgetTenant();

        Sanctum::actingAs($admin);
        $headers = $this->tenantHeader($tenant);

        // One order of 2 cases (total 24000), with a partial payment of 10000.
        $id = $this->postJson('/api/v1/orders', [
            'customer_id' => $customer->getKey(),
            'items' => [['inventory_item_id' => $wine->getKey(), 'quantity' => 2, 'unit_type' => 'cases']],
        ], $headers)->assertCreated()->json('data.id');
        $this->postJson("/api/v1/orders/{$id}/payments", ['amount' => 10000], $headers)->assertCreated();

        $this->getJson('/api/v1/dashboard?range=30D', $headers)
            ->assertOk()
            ->assertJsonPath('data.stats.total_orders', 1)
            ->assertJsonPath('data.stats.customers', 1)
            ->assertJsonPath('data.stats.revenue', 24000)
            ->assertJsonPath('data.stats.outstanding_ar', 14000) // 24000 billed - 10000 received
            ->assertJsonPath('data.stats.tasks_overdue', 1)
            ->assertJsonPath('data.order_status.0', ['key' => 'received', 'value' => 1])
            ->assertJsonPath('data.top_products.0.name', 'Plavac')
            ->assertJsonPath('data.recent_orders.0.customer', 'Konoba')
            ->assertJsonPath('data.recent_orders.0.total', 24000);
    }

    /**
     * `ready_to_ship`, like `low_stock` and `tasks_overdue`, is the tenant's
     * current state — a SHIPPED order from months ago must not still count.
     */
    public function test_ready_to_ship_counts_current_orders_regardless_of_period(): void
    {
        $tenant = $this->createTenant();
        $admin = $this->createMember($tenant, [TenantRole::Admin]);
        $this->actingAsTenant($tenant);
        $customer = Customer::create(['company_name' => 'Co', 'email' => 'c@example.com']);
        Order::create(['order_number' => 'RTS-1', 'status' => OrderStatus::ReadyToShip->value, 'total_amount' => 1000, 'customer_id' => $customer->getKey(), 'created_by_id' => $admin->getKey(), 'is_consignment' => false]);
        Order::create(['order_number' => 'RTS-2', 'status' => OrderStatus::Shipped->value, 'total_amount' => 1000, 'customer_id' => $customer->getKey(), 'created_by_id' => $admin->getKey(), 'is_consignment' => false]);
        $this->forgetTenant();

        Sanctum::actingAs($admin);
        $this->getJson('/api/v1/dashboard?range=30D', $this->tenantHeader($tenant))
            ->assertOk()
            ->assertJsonPath('data.stats.ready_to_ship', 1);
    }

    /** The revenue chart is always the trailing 6 calendar months, whatever period tab is selected. */
    public function test_revenue_trend_buckets_by_calendar_month_regardless_of_period(): void
    {
        $tenant = $this->createTenant();
        $admin = $this->createMember($tenant, [TenantRole::Admin]);
        $this->actingAsTenant($tenant);
        $customer = Customer::create(['company_name' => 'Co', 'email' => 'c@example.com']);
        $order = Order::create(['order_number' => 'OLD', 'status' => OrderStatus::Received->value, 'total_amount' => 5000, 'customer_id' => $customer->getKey(), 'created_by_id' => $admin->getKey(), 'is_consignment' => false]);
        $order->forceFill(['created_at' => now()->subMonthsNoOverflow(2)])->save();
        $this->forgetTenant();

        Sanctum::actingAs($admin);
        // period=today would otherwise report zero revenue for everything.
        $trend = $this->getJson('/api/v1/dashboard?period=today', $this->tenantHeader($tenant))
            ->assertOk()
            ->json('data.revenue_trend');

        $this->assertCount(6, $trend);
        $this->assertSame(now()->format('M'), $trend[5]['label']);
        $monthTwoAgo = collect($trend)->firstWhere('label', now()->subMonthsNoOverflow(2)->format('M'));
        $this->assertSame(5000, $monthTwoAgo['value']);
    }
}
