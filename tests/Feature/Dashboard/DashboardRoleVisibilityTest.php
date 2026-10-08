<?php

declare(strict_types=1);

namespace Tests\Feature\Dashboard;

use App\Enums\OrderStatus;
use App\Enums\TaskStatus;
use App\Enums\TenantRole;
use App\Models\Cost;
use App\Models\Customer;
use App\Models\InventoryItem;
use App\Models\Order;
use App\Models\Tenant;
use App\Models\WorkOrder;
use App\Services\Auth\ActiveTenantSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\InteractsWithTenancy;
use Tests\TestCase;

/**
 * The dashboard shows each member only the blocks their role may see anywhere else in the app, and
 * the server does not send (or even compute) the rest. The blocks and the permission behind each:
 *
 *   revenue  financials.view  revenue, trend, channels, target, top products
 *   finance  finance.view     key ratios (costs and salaries), cash flow, runway, receivables
 *   orders   orders.view      order counts, status, ready-to-ship
 *   stock    inventory.view   low stock
 *   reorder  customers.create the reorder pipeline
 *   tasks    work_orders.use  upcoming and overdue tasks
 */
class DashboardRoleVisibilityTest extends TestCase
{
    use InteractsWithTenancy;
    use RefreshDatabase;

    /** Which response keys belong to which block. */
    private const KEYS = [
        'revenue' => ['revenue_summary', 'revenue_by_channel', 'revenue_trend', 'revenue', 'top_products', 'revenue_vs_target'],
        'finance' => ['key_ratios', 'net_cash_flow', 'runway'],
        'orders' => ['orders', 'order_status'],
        'stock' => ['stock_watch'],
        'reorder' => ['reorder_pipeline'],
        'tasks' => ['upcoming_tasks'],
    ];

    /** And which of the stats. */
    private const STATS = [
        'revenue' => ['revenue'],
        'finance' => ['outstanding_ar'],
        'orders' => ['total_orders', 'ready_to_ship'],
        'stock' => ['low_stock'],
        'tasks' => ['tasks_overdue'],
    ];

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = $this->createTenant();
        $admin = $this->createMember($this->tenant, [TenantRole::Admin]);

        $this->actingAsTenant($this->tenant);
        $customer = Customer::create(['company_name' => 'Secret Customer d.o.o.', 'email' => 'c@example.test', 'customer_type' => 'WHOLESALE']);
        Order::create(['order_number' => 'SECRET-ORDER-1', 'status' => OrderStatus::ReadyToShip->value, 'total_amount' => 987654, 'customer_id' => $customer->getKey(), 'created_by_id' => $admin->getKey(), 'is_consignment' => false]);
        Cost::create(['date' => now(), 'total_amount' => 555555, 'category' => 'Salary', 'description' => 'SECRET-SALARY', 'status' => 'PAID', 'created_by_id' => $admin->getKey()]);
        InventoryItem::create(['name' => 'Secret Cork', 'sku' => 'C', 'category' => 'RAW_MATERIAL', 'unit' => 'units', 'current_stock' => '5', 'min_stock' => '20']);
        WorkOrder::create(['title' => 'Secret task', 'status' => TaskStatus::Todo, 'priority' => 'MEDIUM', 'created_by_id' => $admin->getKey(), 'due_date' => now()->subDay()]);
        $this->forgetTenant();
    }

    /**
     * @param  list<TenantRole>  $roles
     * @return array<string, mixed>
     */
    private function summary(array $roles): array
    {
        Sanctum::actingAs($this->createMember($this->tenant, $roles));

        return $this->getJson('/api/v1/dashboard?period=ytd', $this->tenantHeader($this->tenant))->assertOk()->json('data');
    }

    /** @return array<string, array{list<TenantRole>, list<string>}> */
    public static function roles(): array
    {
        return [
            'admin' => [[TenantRole::Admin], ['revenue', 'finance', 'orders', 'stock', 'reorder', 'tasks']],
            'team' => [[TenantRole::Team], ['revenue', 'orders', 'stock', 'reorder', 'tasks']],
            'orders' => [[TenantRole::Orders], ['revenue', 'orders', 'reorder', 'tasks']],
            'cellar' => [[TenantRole::Cellar], ['tasks']],
            'inventory' => [[TenantRole::Inventory], ['stock']],
            'manager' => [[TenantRole::Manager], ['revenue']],
            'sales' => [[TenantRole::Sales], ['revenue']],
            'hospitality' => [[TenantRole::Hospitality], []],
            'kitchen' => [[TenantRole::Kitchen], []],
            'employee' => [[TenantRole::Employee], []],
            'wine club' => [[TenantRole::WineClub], []],
            'orders + inventory' => [[TenantRole::Orders, TenantRole::Inventory], ['revenue', 'orders', 'stock', 'reorder', 'tasks']],
            // The old menu hid work orders from anyone holding MANAGER, so their tasks stay hidden too.
            'manager + orders' => [[TenantRole::Manager, TenantRole::Orders], ['revenue', 'orders', 'reorder']],
        ];
    }

    /**
     * @param  list<TenantRole>  $roles
     * @param  list<string>  $expected
     *
     * @dataProvider roles
     */
    #[DataProvider('roles')]
    public function test_each_role_gets_exactly_its_blocks(array $roles, array $expected): void
    {
        $data = $this->summary($roles);

        $this->assertEqualsCanonicalizing($expected, $data['visible']);

        foreach (self::KEYS as $section => $keys) {
            foreach ($keys as $key) {
                if (in_array($section, $expected, true)) {
                    // Runway is legitimately empty until cash on hand is set in Settings; the card says so itself.
                    if ($key !== 'runway') {
                        $this->assertNotNull($data[$key], "{$key} should be sent");
                    }
                } else {
                    $this->assertNull($data[$key], "{$key} must not be sent");
                }
            }
        }
        foreach (self::STATS as $section => $keys) {
            foreach ($keys as $key) {
                in_array($section, $expected, true)
                    ? $this->assertNotNull($data['stats'][$key], "stats.{$key} should be sent")
                    : $this->assertNull($data['stats'][$key], "stats.{$key} must not be sent");
            }
        }
        // customers is a customers.view figure: administrators only.
        in_array(TenantRole::Admin, $roles, true)
            ? $this->assertNotNull($data['stats']['customers'])
            : $this->assertNull($data['stats']['customers']);
    }

    public function test_recent_orders_list_prices_so_it_needs_both_orders_and_money(): void
    {
        $this->assertNotNull($this->summary([TenantRole::Orders])['recent_orders']);
        $this->assertNotNull($this->summary([TenantRole::Admin])['recent_orders']);
        $this->assertNull($this->summary([TenantRole::Inventory])['recent_orders']);
        $this->assertNull($this->summary([TenantRole::Manager])['recent_orders'], 'a manager sees figures but not the order list');
    }

    /** @return array<string, array{TenantRole, list<string>}> role => text that must NOT appear anywhere in its response */
    public static function secrets(): array
    {
        return [
            'cellar sees no orders, money, costs or stock' => [TenantRole::Cellar, ['SECRET-ORDER-1', 'Secret Customer', '987654', 'SECRET-SALARY', '555555', 'Secret Cork']],
            'inventory sees no orders, money or tasks' => [TenantRole::Inventory, ['SECRET-ORDER-1', 'Secret Customer', '987654', 'SECRET-SALARY', '555555', 'Secret task']],
            'team sees no cost or salary data' => [TenantRole::Team, ['SECRET-SALARY', '555555']],
            'orders sees no cost data, stock or customers count' => [TenantRole::Orders, ['SECRET-SALARY', '555555', 'Secret Cork']],
            'manager sees no orders list, stock, tasks or costs' => [TenantRole::Manager, ['SECRET-ORDER-1', 'Secret Customer', 'SECRET-SALARY', '555555', 'Secret Cork', 'Secret task']],
            'hospitality sees nothing' => [TenantRole::Hospitality, ['SECRET-ORDER-1', 'Secret Customer', '987654', 'SECRET-SALARY', 'Secret Cork', 'Secret task']],
        ];
    }

    /**
     * @param  list<string>  $forbidden
     *
     * @dataProvider secrets
     */
    #[DataProvider('secrets')]
    public function test_a_restricted_role_never_receives_the_underlying_data(TenantRole $role, array $forbidden): void
    {
        $json = (string) json_encode($this->summary([$role]));

        foreach ($forbidden as $text) {
            $this->assertStringNotContainsString($text, $json, "{$role->value} must not receive {$text}");
        }
    }

    public function test_an_admin_does_receive_the_data(): void
    {
        $json = (string) json_encode($this->summary([TenantRole::Admin]));

        foreach (['SECRET-ORDER-1', 'Secret Customer', '987654', 'Secret Cork', 'Secret task'] as $text) {
            $this->assertStringContainsString($text, $json);
        }
    }

    public function test_the_web_dashboard_applies_the_same_rules(): void
    {
        $team = $this->createMember($this->tenant, [TenantRole::Team]);

        $this->actingAs($team)->withSession([ActiveTenantSession::KEY => $this->tenant->getKey()])
            ->get('/dashboard?period=ytd')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Dashboard')
            ->where('summary.runway', null)
            ->where('summary.key_ratios', null)
            ->where('summary.net_cash_flow', null)
            ->where('summary.stats.outstanding_ar', null)
            ->whereNot('summary.revenue_summary', null)
            ->where('summary.visible', fn ($visible) => array_diff($visible->all(), ['revenue', 'orders', 'stock', 'reorder', 'tasks']) === []));
    }

    public function test_a_role_with_nothing_still_gets_a_dashboard_page_not_a_refusal(): void
    {
        $kitchen = $this->createMember($this->tenant, [TenantRole::Kitchen]);

        $this->actingAs($kitchen)->withSession([ActiveTenantSession::KEY => $this->tenant->getKey()])
            ->get('/dashboard')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->where('summary.visible', [])
            ->where('summary.revenue_summary', null)
            ->where('summary.stats.total_orders', null));
    }

    public function test_blocks_a_role_cannot_see_are_not_even_queried(): void
    {
        $this->actingAsTenant($this->tenant);
        $queries = [];
        DB::listen(function ($q) use (&$queries): void {
            $queries[] = $q->sql;
        });
        $this->forgetTenant();

        $this->summary([TenantRole::Hospitality]);

        // The costs table is the sharpest probe: a role with no finance access must not trigger a cost query.
        $this->assertSame([], array_values(array_filter($queries, fn (string $sql) => str_contains($sql, 'from "costs"') || str_contains($sql, 'from `costs`'))));
    }
}
