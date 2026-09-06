<?php

declare(strict_types=1);

namespace Tests\Feature\Customers;

use App\Enums\TenantRole;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\InteractsWithTenancy;
use Tests\TestCase;

class CustomerAnalyticsTest extends TestCase
{
    use InteractsWithTenancy;
    use RefreshDatabase;

    private Tenant $tenant;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-06-10 12:00:00');
        $this->tenant = $this->createTenant();
        $this->admin = $this->createMember($this->tenant, [TenantRole::Admin]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @param array<string, mixed> $extra */
    private function order(Customer $c, int $minor, string $at, array $extra = []): void
    {
        $this->actingAsTenant($this->tenant);
        $o = Order::create(array_merge([
            'order_number' => 'ORD-'.uniqid(), 'customer_id' => $c->getKey(),
            'created_by_id' => $this->admin->getKey(), 'total_amount' => $minor,
        ], $extra));
        $o->forceFill(['created_at' => Carbon::parse($at)])->save();
        $this->forgetTenant();
    }

    /** @return array<string, string> */
    private function headers(): array
    {
        return $this->tenantHeader($this->tenant);
    }

    public function test_customer_analytics_summary_and_table(): void
    {
        $this->actingAsTenant($this->tenant);
        $acme = Customer::create(['company_name' => 'Acme Corporation', 'contact_name' => 'John Smith', 'email' => 'a@example.com']);
        // A customer with no orders should not appear / not count as active.
        Customer::create(['company_name' => 'Dormant Co', 'email' => 'd@example.com']);
        $this->forgetTenant();

        // Acme: one order of €99.95 within the last 12 months.
        $this->order($acme, 9995, '2026-06-08');

        Sanctum::actingAs($this->admin);
        $res = $this->getJson('/api/v1/customers/analytics', $this->headers())->assertOk();

        $res->assertJsonPath('data.summary.active_customers', 1)
            ->assertJsonPath('data.summary.revenue_12m.minor', 9995)
            ->assertJsonPath('data.summary.top_customer.company_name', 'Acme Corporation')
            ->assertJsonPath('data.summary.top_customer.revenue_12m.minor', 9995);

        $res->assertJsonCount(1, 'data.customers')
            ->assertJsonPath('data.customers.0.company_name', 'Acme Corporation')
            ->assertJsonPath('data.customers.0.contact_name', 'John Smith')
            ->assertJsonPath('data.customers.0.revenue_12m.minor', 9995)
            ->assertJsonPath('data.customers.0.revenue_all_time.minor', 9995)
            ->assertJsonPath('data.customers.0.order_count_12m', 1)
            ->assertJsonPath('data.customers.0.avg_order_value.minor', 9995)
            ->assertJsonPath('data.customers.0.days_since_last_order', 2)  // Jun 8 → Jun 10
            ->assertJsonPath('data.customers.0.median_gap_days', null)     // one order → no gap
            ->assertJsonPath('data.customers.0.expected_next_order_date', null); // <3 orders
    }

    public function test_median_gap_and_expected_next_with_a_cadence(): void
    {
        $this->actingAsTenant($this->tenant);
        $c = Customer::create(['company_name' => 'Regular Co', 'email' => 'r@example.com']);
        $this->forgetTenant();

        // Three orders ~30 days apart → median gap 30, expected next ≈ last + 30.
        $this->order($c, 1000, '2026-04-01');
        $this->order($c, 1000, '2026-05-01');
        $this->order($c, 1000, '2026-05-31');

        Sanctum::actingAs($this->admin);
        $this->getJson('/api/v1/customers/analytics', $this->headers())
            ->assertOk()
            ->assertJsonPath('data.customers.0.median_gap_days', 30)
            ->assertJsonPath('data.customers.0.expected_next_order_date', '2026-06-30T00:00:00+00:00');
    }

    public function test_analytics_requires_financials_visibility(): void
    {
        $member = $this->createMember($this->tenant, [TenantRole::Cellar]);
        Sanctum::actingAs($member);
        $this->getJson('/api/v1/customers/analytics', $this->headers())->assertForbidden();
    }

    public function test_revenue_12m_and_revenue_all_time_are_genuinely_different_windows(): void
    {
        $this->actingAsTenant($this->tenant);
        $c = Customer::create(['company_name' => 'Long History Co', 'email' => 'lh@example.com']);
        $this->forgetTenant();

        // One order well outside the 12-month window, one order well inside it.
        $oldDate = '2024-01-01';
        $recentDate = '2026-06-08';
        $this->order($c, 50000, $oldDate);
        $this->order($c, 9995, $recentDate);

        Sanctum::actingAs($this->admin);
        $res = $this->getJson('/api/v1/customers/analytics', $this->headers())->assertOk();

        // all-time includes both orders; 12m includes only the recent one.
        $res->assertJsonPath('data.customers.0.revenue_all_time.minor', 59995)
            ->assertJsonPath('data.customers.0.revenue_12m.minor', 9995)
            ->assertJsonPath('data.customers.0.order_count_12m', 1)
            ->assertJsonPath('data.customers.0.avg_order_value.minor', 9995);

        // The summary total also only reflects the 12m window.
        $res->assertJsonPath('data.summary.revenue_12m.minor', 9995);

        // median_gap_days is computed from the FULL order history (not just the 12m
        // window), so it reflects the gap between the old and the recent order.
        $expectedGap = (int) Carbon::parse($oldDate)->diffInDays(Carbon::parse($recentDate));
        $res->assertJsonPath('data.customers.0.median_gap_days', $expectedGap);
    }

    public function test_median_gap_with_an_odd_number_of_gaps_uses_the_true_middle_value(): void
    {
        $this->actingAsTenant($this->tenant);
        $c = Customer::create(['company_name' => 'Uneven Co', 'email' => 'uneven@example.com']);
        $this->forgetTenant();

        // 4 orders → 3 gaps of 10, 20, 30 days. Sorted: [10, 20, 30] → true middle is 20
        // (not an average, since the gap count is odd).
        $this->order($c, 1000, '2026-01-01');
        $this->order($c, 1000, '2026-01-11'); // +10
        $this->order($c, 1000, '2026-01-31'); // +20
        $this->order($c, 1000, '2026-03-02'); // +30

        Sanctum::actingAs($this->admin);
        $this->getJson('/api/v1/customers/analytics', $this->headers())
            ->assertOk()
            ->assertJsonPath('data.customers.0.median_gap_days', 20)
            ->assertJsonPath('data.customers.0.expected_next_order_date', '2026-03-22T00:00:00+00:00');
    }

    public function test_exclude_from_stats_customer_is_excluded_entirely(): void
    {
        $this->actingAsTenant($this->tenant);
        $visible = Customer::create(['company_name' => 'Visible Co', 'email' => 'vis@example.com']);
        $hidden = Customer::create(['company_name' => 'Hidden Co', 'email' => 'hid@example.com', 'exclude_from_stats' => true]);
        $this->forgetTenant();

        $this->order($visible, 5000, '2026-06-01');
        // Hidden has a bigger, more recent order — would be top_customer if it counted.
        $this->order($hidden, 999999, '2026-06-05');

        Sanctum::actingAs($this->admin);
        $res = $this->getJson('/api/v1/customers/analytics', $this->headers())->assertOk();

        $res->assertJsonPath('data.summary.active_customers', 1)
            ->assertJsonPath('data.summary.revenue_12m.minor', 5000)
            ->assertJsonPath('data.summary.top_customer.company_name', 'Visible Co');

        $res->assertJsonCount(1, 'data.customers')
            ->assertJsonPath('data.customers.0.company_name', 'Visible Co');

        $names = array_column($res->json('data.customers'), 'company_name');
        $this->assertNotContains('Hidden Co', $names);
    }

    public function test_consignment_orders_are_excluded_from_revenue_and_cadence(): void
    {
        $this->actingAsTenant($this->tenant);
        $c = Customer::create(['company_name' => 'Consignment Mix Co', 'email' => 'cm@example.com']);
        $this->forgetTenant();

        $this->order($c, 5000, '2026-06-01');
        // A much larger consignment order in the same window must not affect any total.
        $this->order($c, 999999, '2026-06-05', ['is_consignment' => true]);

        Sanctum::actingAs($this->admin);
        $this->getJson('/api/v1/customers/analytics', $this->headers())
            ->assertOk()
            ->assertJsonPath('data.customers.0.revenue_12m.minor', 5000)
            ->assertJsonPath('data.customers.0.revenue_all_time.minor', 5000)
            ->assertJsonPath('data.customers.0.order_count_12m', 1)
            ->assertJsonPath('data.customers.0.avg_order_value.minor', 5000)
            // Only one non-consignment order exists → still no median possible.
            ->assertJsonPath('data.customers.0.median_gap_days', null);
    }

    public function test_customer_with_only_consignment_orders_does_not_appear_at_all(): void
    {
        $this->actingAsTenant($this->tenant);
        $consignmentOnly = Customer::create(['company_name' => 'Consignment Only Co', 'email' => 'co@example.com']);
        $this->forgetTenant();

        $this->order($consignmentOnly, 12345, '2026-06-01', ['is_consignment' => true]);

        Sanctum::actingAs($this->admin);
        $res = $this->getJson('/api/v1/customers/analytics', $this->headers())->assertOk();

        $res->assertJsonPath('data.summary.active_customers', 0)
            ->assertJsonPath('data.summary.revenue_12m.minor', 0)
            ->assertJsonPath('data.summary.top_customer', null)
            ->assertJsonCount(0, 'data.customers');
    }

    public function test_top_customer_is_null_when_the_top_ranked_row_has_zero_recent_revenue(): void
    {
        $this->actingAsTenant($this->tenant);
        $c = Customer::create(['company_name' => 'All Old Orders Co', 'email' => 'old@example.com']);
        $this->forgetTenant();

        // Only order is well outside the 12m window → revenue_12m is 0 for this (only) customer.
        $this->order($c, 50000, '2023-01-01');

        Sanctum::actingAs($this->admin);
        $res = $this->getJson('/api/v1/customers/analytics', $this->headers())->assertOk();

        $res->assertJsonPath('data.summary.active_customers', 1)
            ->assertJsonPath('data.summary.revenue_12m.minor', 0)
            ->assertJsonPath('data.summary.top_customer', null)
            ->assertJsonPath('data.customers.0.revenue_12m.minor', 0)
            ->assertJsonPath('data.customers.0.revenue_all_time.minor', 50000)
            ->assertJsonPath('data.customers.0.order_count_12m', 0)
            ->assertJsonPath('data.customers.0.avg_order_value.minor', 0);
    }

    public function test_customers_are_sorted_by_revenue_12m_descending(): void
    {
        $this->actingAsTenant($this->tenant);
        $low = Customer::create(['company_name' => 'Low Spender', 'email' => 'low@example.com']);
        $high = Customer::create(['company_name' => 'High Spender', 'email' => 'high@example.com']);
        $this->forgetTenant();

        $this->order($low, 1000, '2026-06-01');
        $this->order($high, 90000, '2026-06-01');

        Sanctum::actingAs($this->admin);
        $res = $this->getJson('/api/v1/customers/analytics', $this->headers())->assertOk();

        $res->assertJsonPath('data.customers.0.company_name', 'High Spender')
            ->assertJsonPath('data.customers.1.company_name', 'Low Spender')
            ->assertJsonPath('data.summary.top_customer.company_name', 'High Spender');
    }
}
