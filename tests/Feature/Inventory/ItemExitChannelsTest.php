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

/**
 * The product page's "Exit by channel": exits split by the customer's channel (Internal / POS,
 * Distributor / Importer, Retailer / Shop), with revenue, healed against the live orders.
 */
class ItemExitChannelsTest extends TestCase
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

        $this->item = InventoryItem::create([
            'name' => 'R3', 'sku' => 'R3', 'category' => 'FINISHED', 'unit' => 'bottles', 'sales_unit' => 'bottles',
            'bottles_per_case' => 12, 'current_stock' => '1000', 'default_price' => 1200,
        ]);

        $pos = $this->customer('Tasting room', ['exclude_from_stats' => true, 'customer_type' => 'RETAIL']);
        $distributor = $this->customer('Importer', ['customer_type' => 'WHOLESALE']);
        $shop = $this->customer('Delikatesa', ['customer_type' => 'RETAIL']);
        $ghost = $this->customer('Ghost', ['customer_type' => 'WHOLESALE']);

        $this->sale('P-1', $pos, 30, 800);
        $this->sale('D-1', $distributor, 50, 900);
        $this->sale('S-1', $shop, 10, 1100);

        // A deduct for an order that was later deleted: it never really left, so it is excluded.
        $this->movement('ORDER_DEDUCT', -7, 'GONE-1');
        // A production exit, a count correction (ignored) and an adjustment (ignored).
        $this->movement('PRODUCTION_OUT', -5, null);
        $this->movement('MANUAL_OUT', -99, null, reconciliation: true);
        $this->movement('ADJUSTMENT', -40, null);
        unset($ghost);

        $this->forgetTenant();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @param array<string, mixed> $attributes */
    private function customer(string $name, array $attributes): Customer
    {
        return Customer::create(array_merge(['company_name' => $name, 'email' => str($name)->slug().'@example.test'], $attributes));
    }

    private function sale(string $number, Customer $customer, int $bottles, int $unitMinor): void
    {
        $order = Order::create([
            'order_number' => $number, 'customer_id' => $customer->getKey(),
            'created_by_id' => $this->admin->getKey(), 'total_amount' => $bottles * $unitMinor,
        ]);
        $order->forceFill(['created_at' => Carbon::parse('2026-06-10')])->save();
        $order->items()->create([
            'inventory_item_id' => $this->item->getKey(), 'quantity' => $bottles,
            'unit_type' => 'bottles', 'unit_price' => $unitMinor, 'total' => $bottles * $unitMinor,
        ]);
        $this->movement('ORDER_DEDUCT', -$bottles, $number);
    }

    private function movement(string $type, int $qty, ?string $reference, bool $reconciliation = false): void
    {
        $m = StockMovement::create([
            'inventory_item_id' => $this->item->getKey(), 'type' => $type, 'quantity' => $qty,
            'reference' => $reference, 'is_reconciliation' => $reconciliation,
        ]);
        $m->forceFill(['created_at' => Carbon::parse('2026-06-12')])->save();
    }

    /** @return array<string, mixed> */
    /**
     * @return array<string, mixed>
     */
    private function channels(string $period = '30d'): array
    {
        Sanctum::actingAs($this->admin);

        return $this->getJson("/api/v1/inventory-items/{$this->item->getKey()}/stock-analytics?period={$period}", $this->tenantHeader($this->tenant))
            ->assertOk()
            ->json('data.channels_by_customer');
    }

    public function test_exits_are_split_by_customer_channel_with_revenue(): void
    {
        $result = $this->channels();
        /** @var list<array<string, mixed>> $list */
        $list = $result['rows'];
        $rows = array_column($list, null, 'key');

        $this->assertSame(30, $rows['internal']['bottles']);
        $this->assertSame(50, $rows['distributor']['bottles']);
        $this->assertSame(10, $rows['retailer']['bottles']);
        $this->assertSame(5, $rows['non_order']['bottles']);

        $this->assertSame(30 * 800, $rows['internal']['revenue']['minor']);
        $this->assertSame(50 * 900, $rows['distributor']['revenue']['minor']);
        $this->assertSame(10 * 1100, $rows['retailer']['revenue']['minor']);
        $this->assertNull($rows['non_order']['revenue']);

        $this->assertSame(95, $result['total_bottles']);
        $this->assertEqualsWithDelta(1.0, array_sum(array_column($list, 'share')), 0.001);
    }

    public function test_deducts_for_deleted_orders_are_reported_as_excluded_not_counted(): void
    {
        $result = $this->channels();

        $this->assertSame(7, $result['excluded_bottles']);
        // 95 counted + 7 excluded = every genuine exit; the correction and adjustment are in neither.
        $this->assertSame(95, $result['total_bottles']);
    }

    public function test_the_histogram_gets_an_internal_series_that_adds_up(): void
    {
        $result = $this->channels();

        $this->assertSame(30, array_sum($result['spark_internal']));
        $this->assertCount(30, $result['spark_internal']);
    }

    public function test_nothing_in_the_window_gives_empty_channels(): void
    {
        $result = $this->channels('today');

        $this->assertSame([], $result['rows']);
        $this->assertSame(0, $result['total_bottles']);
        $this->assertSame(0, $result['excluded_bottles']);
    }

    public function test_headline_histogram_and_channels_describe_the_same_exits(): void
    {
        Sanctum::actingAs($this->admin);
        $data = $this->getJson("/api/v1/inventory-items/{$this->item->getKey()}/stock-analytics?period=30d", $this->tenantHeader($this->tenant))
            ->assertOk()
            ->json('data');

        // The count correction, the adjustment and the deleted order's deduct are in none of them.
        $this->assertSame(95, $data['exits']['bottles_exited']);
        $this->assertSame(95, $data['channels_by_customer']['total_bottles']);
        $this->assertSame(95, array_sum($data['exits']['spark']));
        $this->assertSame(array_sum(array_column($data['channels_by_customer']['rows'], 'bottles')), $data['exits']['bottles_exited']);
        // 4 order deducts that still stand + 1 production exit.
        $this->assertSame(4, $data['exits']['movements_count']);
        // The Internal / POS footnote is the same figure as its channel row.
        $this->assertSame(30, $data['exits']['internal']['bottles']);
        $this->assertSame('2026-05-16', $data['channels_by_customer']['spark_start']);
        $this->assertEquals(1.0, $data['channels_by_customer']['spark_step_days']);
    }
}
