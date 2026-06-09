<?php

declare(strict_types=1);

namespace Tests\Feature\Customers;

use App\Enums\TenantRole;
use App\Models\Customer;
use App\Models\InventoryItem;
use App\Models\Order;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\InteractsWithTenancy;
use Tests\TestCase;

class CustomerOrderAnalyticsTest extends TestCase
{
    use InteractsWithTenancy;
    use RefreshDatabase;

    private Tenant $tenant;

    private User $admin;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-06-15 12:00:00'); // deterministic year/month math
        $this->tenant = $this->createTenant();
        $this->admin = $this->createMember($this->tenant, [TenantRole::Admin]);
        $this->actingAsTenant($this->tenant);
        $this->customer = Customer::create(['company_name' => 'Konoba', 'email' => 'k@example.com']);
        $this->forgetTenant();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @return array<string, string> */
    private function headers(): array
    {
        return $this->tenantHeader($this->tenant);
    }

    private function orderAt(Customer $customer, string $at, int $minor): void
    {
        static $n = 0;
        $n++;
        $order = Order::create([
            'order_number' => 'ORD-'.str_pad((string) $n, 5, '0', STR_PAD_LEFT),
            'customer_id' => $customer->getKey(),
            'created_by_id' => $this->admin->getKey(),
            'total_amount' => $minor,
        ]);
        $order->forceFill(['created_at' => Carbon::parse($at)])->save();
    }

    public function test_order_analytics_reports_revenue_growth_and_projections(): void
    {
        $this->actingAsTenant($this->tenant);
        $this->orderAt($this->customer, '2024-06-15', 10000);
        $this->orderAt($this->customer, '2025-04-15', 10000);
        $this->orderAt($this->customer, '2025-06-10', 10000);
        $this->orderAt($this->customer, '2025-08-15', 8000);  // prior-quarter window (Jul–Sep 2025)
        $this->orderAt($this->customer, '2026-04-15', 20000);
        $this->orderAt($this->customer, '2026-06-10', 10000);
        $this->forgetTenant();

        Sanctum::actingAs($this->admin);
        $this->getJson("/api/v1/customers/{$this->customer->getKey()}/order-analytics", $this->headers())
            ->assertOk()
            ->assertJsonPath('data.total_revenue.minor', 68000)
            ->assertJsonPath('data.this_year.minor', 30000)
            ->assertJsonPath('data.last_year.minor', 28000)
            // trailing 3mo 30000 vs prior 20000 → +50%
            ->assertJsonPath('data.yoy_growth_percent', '50.00')
            // YTD 30000 ÷ (166/365 of year elapsed)
            ->assertJsonPath('data.annual_projection.minor', 65964)
            // same quarter last year (8000) × (1 + 0.5)
            ->assertJsonPath('data.next_quarter_projection.minor', 12000)
            ->assertJsonPath('data.last_order_date', fn ($v) => is_string($v))
            ->assertJsonPath('data.expected_next_order_date', fn ($v) => is_string($v));
    }

    public function test_never_ordered_customer_zeroes_out(): void
    {
        Sanctum::actingAs($this->admin);
        $this->getJson("/api/v1/customers/{$this->customer->getKey()}/order-analytics", $this->headers())
            ->assertOk()
            ->assertJsonPath('data.total_revenue.minor', 0)
            ->assertJsonPath('data.this_year.minor', 0)
            ->assertJsonPath('data.last_year.minor', 0)
            ->assertJsonPath('data.yoy_growth_percent', '0.00')
            ->assertJsonPath('data.last_order_date', null)
            ->assertJsonPath('data.expected_next_order_date', null);
    }

    public function test_order_analytics_requires_financials_visibility(): void
    {
        $member = $this->createMember($this->tenant, [TenantRole::Cellar]);
        Sanctum::actingAs($member);
        $this->getJson("/api/v1/customers/{$this->customer->getKey()}/order-analytics", $this->headers())
            ->assertForbidden();
    }

    public function test_orders_index_can_filter_by_customer(): void
    {
        $this->actingAsTenant($this->tenant);
        $other = Customer::create(['company_name' => 'Other', 'email' => 'o@example.com']);
        $this->orderAt($this->customer, '2026-05-01', 5000);
        $this->orderAt($this->customer, '2026-05-02', 7000);
        $this->orderAt($other, '2026-05-03', 9000);
        $this->forgetTenant();

        Sanctum::actingAs($this->admin);
        $this->getJson("/api/v1/orders?customer_id={$this->customer->getKey()}", $this->headers())
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.total', 2);
    }

    public function test_custom_prices_are_listed_for_a_customer(): void
    {
        $this->actingAsTenant($this->tenant);
        $item = InventoryItem::create([
            'name' => 'Plavac', 'sku' => 'PLV', 'category' => 'FINISHED', 'unit' => 'bottles',
            'sales_unit' => 'bottles', 'bottles_per_case' => 12, 'default_price' => 1500, 'cost_per_unit' => 700,
        ]);
        $this->forgetTenant();

        Sanctum::actingAs($this->admin);
        $this->putJson(
            "/api/v1/inventory-items/{$item->getKey()}/customer-price/{$this->customer->getKey()}",
            ['price' => 1200],
            $this->headers(),
        )->assertOk();

        $this->getJson("/api/v1/customers/{$this->customer->getKey()}/custom-prices", $this->headers())
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.inventory_item_id', $item->getKey())
            ->assertJsonPath('data.0.name', 'Plavac')
            ->assertJsonPath('data.0.price.minor', 1200);
    }
}
