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

    private function order(Customer $c, int $minor, string $at): void
    {
        $this->actingAsTenant($this->tenant);
        $o = Order::create([
            'order_number' => 'ORD-'.uniqid(), 'customer_id' => $c->getKey(),
            'created_by_id' => $this->admin->getKey(), 'total_amount' => $minor,
        ]);
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
}
