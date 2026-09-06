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

class ReorderRadarTest extends TestCase
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

    /** @return array<string, string> */
    private function headers(): array
    {
        return $this->tenantHeader($this->tenant);
    }

    /** @param array<string, mixed> $extra */
    private function orderAt(Customer $customer, int $daysAgo, int $minor = 10000, array $extra = []): Order
    {
        static $n = 0;
        $n++;
        $order = Order::create(array_merge([
            'order_number' => 'ORD-'.str_pad((string) $n, 5, '0', STR_PAD_LEFT),
            'customer_id' => $customer->getKey(),
            'created_by_id' => $this->admin->getKey(),
            'total_amount' => $minor,
        ], $extra));
        $order->forceFill(['created_at' => now()->subDays($daysAgo)])->save();

        return $order;
    }

    /** @return array<string, mixed> */
    private function fetchRadar(): array
    {
        return (array) $this->getJson('/api/v1/customers/reorder-radar', $this->headers())->assertOk()->json('data');
    }

    public function test_overdue_customer_is_flagged_and_muted_after_contact(): void
    {
        $this->actingAsTenant($this->tenant);
        $overdue = Customer::create(['company_name' => 'Slipping Bar', 'email' => 's@example.com']);
        $this->orderAt($overdue, 40);
        $this->orderAt($overdue, 30);
        $this->orderAt($overdue, 20); // median gap 10d, last 20d ago → ratio 2.0 → overdue

        $tooFew = Customer::create(['company_name' => 'New Bar', 'email' => 'n@example.com']);
        $this->orderAt($tooFew, 5);
        $this->orderAt($tooFew, 2); // only 2 orders → excluded
        $this->forgetTenant();

        Sanctum::actingAs($this->admin);

        $response = $this->getJson('/api/v1/customers/reorder-radar', $this->headers())->assertOk();
        $ids = array_column((array) $response->json('data.rows'), 'customer_id');
        $this->assertContains($overdue->getKey(), $ids);
        $this->assertNotContains($tooFew->getKey(), $ids);
        $response->assertJsonPath('data.rows.0.status', 'overdue');
        $this->assertGreaterThanOrEqual(1, $response->json('data.counts.overdue'));

        // Mark contacted → muted on the radar.
        $this->postJson("/api/v1/customers/{$overdue->getKey()}/contacted", ['contacted' => true], $this->headers())
            ->assertOk();

        $ids = array_column((array) $this->getJson('/api/v1/customers/reorder-radar', $this->headers())->json('data.rows'), 'customer_id');
        $this->assertNotContains($overdue->getKey(), $ids);
    }

    public function test_status_thresholds_at_exact_ratio_boundaries_and_counts_match_rows(): void
    {
        $this->actingAsTenant($this->tenant);

        // Each customer: 3 orders spaced $gap days apart, last order $lastAgo days ago.
        // median = $gap, ratio = $lastAgo / $gap.
        $makeCadence = function (string $name, int $gap, int $lastAgo): Customer {
            $customer = Customer::create(['company_name' => $name, 'email' => strtolower(str_replace(' ', '', $name)).'@example.com']);
            $this->orderAt($customer, $lastAgo + 2 * $gap);
            $this->orderAt($customer, $lastAgo + $gap);
            $this->orderAt($customer, $lastAgo);

            return $customer;
        };

        $due1 = $makeCadence('Due One', 10, 10);      // ratio 1.00 → due (lower boundary, inclusive)
        $due2 = $makeCadence('Due Two', 10, 12);      // ratio 1.20 → due
        $overdue = $makeCadence('Overdue Co', 20, 35); // ratio 1.75 → overdue (lower boundary, inclusive)
        $atRisk = $makeCadence('At Risk Co', 10, 30);  // ratio 3.00 → at_risk (lower boundary, inclusive)
        $tooEarly = $makeCadence('Too Early Co', 10, 9); // ratio 0.90 → below "due" threshold, excluded entirely

        $this->forgetTenant();

        Sanctum::actingAs($this->admin);
        $data = $this->fetchRadar();

        $rowsById = collect((array) $data['rows'])->keyBy('customer_id');

        $this->assertSame('due', $rowsById[$due1->getKey()]['status']);
        $this->assertEqualsWithDelta(1.0, $rowsById[$due1->getKey()]['overdue_ratio'], 0.0001);
        $this->assertSame('due', $rowsById[$due2->getKey()]['status']);
        $this->assertSame(1.2, $rowsById[$due2->getKey()]['overdue_ratio']);
        $this->assertSame('overdue', $rowsById[$overdue->getKey()]['status']);
        $this->assertSame(1.75, $rowsById[$overdue->getKey()]['overdue_ratio']);
        $this->assertSame('at_risk', $rowsById[$atRisk->getKey()]['status']);
        $this->assertEqualsWithDelta(3.0, $rowsById[$atRisk->getKey()]['overdue_ratio'], 0.0001);

        // Ratio below 1.0 never appears in the rows at all, not just with a null status.
        $this->assertFalse($rowsById->has($tooEarly->getKey()));

        $this->assertCount(4, $data['rows']);
        $this->assertSame(2, $data['counts']['due']);
        $this->assertSame(1, $data['counts']['overdue']);
        $this->assertSame(1, $data['counts']['at_risk']);
    }

    public function test_customer_with_only_two_orders_never_appears_even_if_wildly_overdue(): void
    {
        $this->actingAsTenant($this->tenant);
        $customer = Customer::create(['company_name' => 'Barely There', 'email' => 'bt@example.com']);
        $this->orderAt($customer, 200);
        $this->orderAt($customer, 100); // 2 orders, huge gap → would be at_risk if it counted
        $this->forgetTenant();

        Sanctum::actingAs($this->admin);
        $data = $this->fetchRadar();

        $ids = array_column($data['rows'], 'customer_id');
        $this->assertNotContains($customer->getKey(), $ids);
        $this->assertSame(0, $data['counts']['due'] + $data['counts']['overdue'] + $data['counts']['at_risk']);
    }

    public function test_contacted_at_exactly_last_order_mutes_but_contacted_before_last_order_does_not(): void
    {
        $this->actingAsTenant($this->tenant);

        // Muted: contacted_at == last order date (>= boundary mutes).
        $mutedCustomer = Customer::create(['company_name' => 'Muted Co', 'email' => 'muted@example.com']);
        $this->orderAt($mutedCustomer, 40);
        $this->orderAt($mutedCustomer, 30);
        $lastOrder = $this->orderAt($mutedCustomer, 20); // ratio 2.0 → overdue, but contacted at same instant as last order
        $mutedCustomer->forceFill(['reorder_contacted_at' => $lastOrder->created_at])->save();

        // Not muted: contacted BEFORE the last order (customer ordered again after being contacted).
        $reactivatedCustomer = Customer::create(['company_name' => 'Reactivated Co', 'email' => 'react@example.com']);
        $this->orderAt($reactivatedCustomer, 40);
        $this->orderAt($reactivatedCustomer, 30);
        $lastOrder2 = $this->orderAt($reactivatedCustomer, 20); // same cadence → ratio 2.0 → overdue
        // Contacted 25 days ago — after the 30-days-ago order but before the 20-days-ago
        // (last) order, i.e. the customer ordered again after being contacted.
        self::assertInstanceOf(Carbon::class, $lastOrder2->created_at);
        $reactivatedCustomer->forceFill(['reorder_contacted_at' => $lastOrder2->created_at->copy()->subDays(5)])->save();

        $this->forgetTenant();

        Sanctum::actingAs($this->admin);
        $data = $this->fetchRadar();

        $ids = array_column($data['rows'], 'customer_id');
        $this->assertNotContains($mutedCustomer->getKey(), $ids);
        $this->assertContains($reactivatedCustomer->getKey(), $ids);

        $row = collect((array) $data['rows'])->firstWhere('customer_id', $reactivatedCustomer->getKey());
        $this->assertSame('overdue', $row['status']);
    }

    public function test_inactive_and_excluded_from_stats_customers_are_never_flagged(): void
    {
        $this->actingAsTenant($this->tenant);

        $inactive = Customer::create(['company_name' => 'Closed Bar', 'email' => 'closed@example.com', 'is_active' => false]);
        $this->orderAt($inactive, 40);
        $this->orderAt($inactive, 30);
        $this->orderAt($inactive, 20); // would be overdue if active

        $excluded = Customer::create(['company_name' => 'Excluded Bar', 'email' => 'excl@example.com', 'exclude_from_stats' => true]);
        $this->orderAt($excluded, 40);
        $this->orderAt($excluded, 30);
        $this->orderAt($excluded, 20); // would be overdue if not excluded

        $this->forgetTenant();

        Sanctum::actingAs($this->admin);
        $data = $this->fetchRadar();

        $ids = array_column($data['rows'], 'customer_id');
        $this->assertNotContains($inactive->getKey(), $ids);
        $this->assertNotContains($excluded->getKey(), $ids);
        $this->assertSame(0, $data['counts']['due'] + $data['counts']['overdue'] + $data['counts']['at_risk']);
    }

    public function test_consignment_orders_are_excluded_from_the_cadence_calculation(): void
    {
        $this->actingAsTenant($this->tenant);

        // Only 2 real orders + a pile of consignment orders → still excluded (MIN_ORDERS counts real orders only).
        $consignmentHeavy = Customer::create(['company_name' => 'Consignment Heavy', 'email' => 'ch@example.com']);
        $this->orderAt($consignmentHeavy, 40);
        $this->orderAt($consignmentHeavy, 30);
        $this->orderAt($consignmentHeavy, 25, 10000, ['is_consignment' => true]);
        $this->orderAt($consignmentHeavy, 15, 10000, ['is_consignment' => true]);
        $this->orderAt($consignmentHeavy, 5, 10000, ['is_consignment' => true]);

        // 3 real orders on a clean 10-day cadence, plus consignment orders that would
        // wreck that cadence (and the average order value) if they leaked in.
        $mixed = Customer::create(['company_name' => 'Mixed Co', 'email' => 'mixed@example.com']);
        $this->orderAt($mixed, 30, 10000);
        $this->orderAt($mixed, 20, 10000);
        $this->orderAt($mixed, 10, 10000); // real cadence: gap 10, last 10 days ago → ratio 1.0 → due, avg 10000
        $this->orderAt($mixed, 9, 999999, ['is_consignment' => true]);
        $this->orderAt($mixed, 1, 999999, ['is_consignment' => true]);

        $this->forgetTenant();

        Sanctum::actingAs($this->admin);
        $data = $this->fetchRadar();

        $ids = array_column($data['rows'], 'customer_id');
        $this->assertNotContains($consignmentHeavy->getKey(), $ids);
        $this->assertContains($mixed->getKey(), $ids);

        $row = collect((array) $data['rows'])->firstWhere('customer_id', $mixed->getKey());
        $this->assertSame(3, $row['order_count']);
        $this->assertSame('due', $row['status']);
        $this->assertEqualsWithDelta(1.0, $row['overdue_ratio'], 0.0001);
        $this->assertEqualsWithDelta(10.0, $row['median_gap_days'], 0.0001);
        $this->assertSame(10000, $row['avg_order_value']['minor']);
    }

    public function test_rows_are_sorted_by_value_weighted_urgency_descending(): void
    {
        $this->actingAsTenant($this->tenant);

        // Low ratio (due, 1.0), but a very high average order value.
        $highValue = Customer::create(['company_name' => 'High Value Co', 'email' => 'hv@example.com']);
        $this->orderAt($highValue, 30, 100000);
        $this->orderAt($highValue, 20, 100000);
        $this->orderAt($highValue, 10, 100000); // gap 10, last 10 days ago → ratio 1.0

        // High ratio (at_risk, capped urgency multiplier at 6), but a tiny average order value.
        $highRatio = Customer::create(['company_name' => 'High Ratio Co', 'email' => 'hr@example.com']);
        $this->orderAt($highRatio, 100, 100);
        $this->orderAt($highRatio, 90, 100);
        $this->orderAt($highRatio, 80, 100); // gap 10, last 80 days ago → ratio 8.0, capped to 6 for urgency

        $this->forgetTenant();

        Sanctum::actingAs($this->admin);
        $data = $this->fetchRadar();

        $rowsById = collect((array) $data['rows'])->keyBy('customer_id');
        $this->assertTrue($rowsById->has($highValue->getKey()));
        $this->assertTrue($rowsById->has($highRatio->getKey()));

        // urgency = min(ratio, 6) * avg_minor
        // highValue: ratio 1.0, avg 100000 → urgency 100000
        // highRatio: ratio 8.0 capped to 6, avg 100 → urgency 600
        $this->assertEqualsWithDelta(100000.0, $rowsById[$highValue->getKey()]['urgency'], 0.0001);
        $this->assertEqualsWithDelta(600.0, $rowsById[$highRatio->getKey()]['urgency'], 0.0001);

        // Sorted descending by urgency → highValue first.
        $this->assertSame($highValue->getKey(), $data['rows'][0]['customer_id']);
        $this->assertSame($highRatio->getKey(), $data['rows'][1]['customer_id']);
    }
}
