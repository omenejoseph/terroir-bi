<?php

declare(strict_types=1);

namespace Tests\Feature\Finance;

use App\Enums\TenantRole;
use App\Models\Customer;
use App\Models\Inflow;
use App\Models\Order;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\InteractsWithTenancy;
use Tests\TestCase;

class ArAgingTest extends TestCase
{
    use InteractsWithTenancy;
    use RefreshDatabase;

    private Tenant $tenant;

    private User $admin;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-06-15 12:00:00'); // deterministic "now" for age math
        $this->tenant = $this->createTenant();
        $this->admin = $this->createMember($this->tenant, [TenantRole::Admin]);
        $this->actingAsTenant($this->tenant);
        $this->customer = Customer::create(['company_name' => 'Konoba', 'email' => 'konoba@example.com']);
        $this->forgetTenant();
        Sanctum::actingAs($this->admin);
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

    private static int $orderSeq = 0;

    /** @param array<string, mixed> $overrides */
    private function makeOrder(int $totalMinor, ?string $createdAt = null, ?string $backorderDate = null, array $overrides = []): Order
    {
        self::$orderSeq++;

        $order = Order::create(array_merge([
            'order_number' => 'ORD-'.str_pad((string) self::$orderSeq, 5, '0', STR_PAD_LEFT),
            'customer_id' => $this->customer->getKey(),
            'created_by_id' => $this->admin->getKey(),
            'total_amount' => $totalMinor,
            'backorder_date' => $backorderDate,
        ], $overrides));

        if ($createdAt !== null) {
            $order->forceFill(['created_at' => Carbon::parse($createdAt)])->save();
        }

        return $order;
    }

    private function payOrder(Order $order, int $amountMinor, bool $isCreditNote = false): Inflow
    {
        return Inflow::create([
            'order_id' => $order->getKey(),
            'customer_id' => $order->customer_id,
            'amount' => $amountMinor,
            'status' => 'RECEIVED',
            'is_credit_note' => $isCreditNote,
            'date' => now(),
            'created_by_id' => $this->admin->getKey(),
        ]);
    }

    public function test_bucket_boundaries_use_the_effective_date(): void
    {
        $this->actingAsTenant($this->tenant);
        $now = Carbon::now();

        // Distinct amounts per order so each bucket's total is unambiguous.
        $this->makeOrder(100000, $now->copy()->subDays(30)->toDateTimeString()); // exactly 30 → current
        $this->makeOrder(200000, $now->copy()->subDays(31)->toDateTimeString()); // 31 → d30 (31-60)
        $this->makeOrder(300000, $now->copy()->subDays(60)->toDateTimeString()); // exactly 60 → d30 (31-60)
        $this->makeOrder(400000, $now->copy()->subDays(61)->toDateTimeString()); // 61 → d60 (61-90)
        $this->makeOrder(500000, $now->copy()->subDays(90)->toDateTimeString()); // exactly 90 → d60 (61-90)
        $this->makeOrder(600000, $now->copy()->subDays(91)->toDateTimeString()); // 91 → d90_plus
        $this->forgetTenant();

        $this->getJson('/api/v1/inflows/aging', $this->headers())
            ->assertOk()
            ->assertJsonPath('data.buckets.current.minor', 100000)
            ->assertJsonPath('data.buckets.31_60.minor', 500000) // 200000 + 300000
            ->assertJsonPath('data.buckets.61_90.minor', 900000) // 400000 + 500000
            ->assertJsonPath('data.buckets.90_plus.minor', 600000)
            ->assertJsonPath('data.total_outstanding.minor', 2100000);
    }

    public function test_partial_payment_reduces_balance_but_order_remains_outstanding(): void
    {
        $this->actingAsTenant($this->tenant);
        $order = $this->makeOrder(10000, Carbon::now()->subDays(5)->toDateTimeString());
        $this->payOrder($order, 4000);
        $this->forgetTenant();

        $this->getJson('/api/v1/inflows/aging', $this->headers())
            ->assertOk()
            ->assertJsonPath('data.total_outstanding.minor', 6000)
            ->assertJsonPath('data.buckets.current.minor', 6000)
            ->assertJsonPath('data.by_customer.0.outstanding.minor', 6000)
            ->assertJsonPath('data.by_customer.0.orders', 1);
    }

    public function test_only_received_inflows_reduce_the_balance(): void
    {
        $this->actingAsTenant($this->tenant);
        $order = $this->makeOrder(10000, Carbon::now()->subDays(5)->toDateTimeString());
        // A pending (not-yet-received) inflow must not count as payment.
        Inflow::create([
            'order_id' => $order->getKey(),
            'customer_id' => $order->customer_id,
            'amount' => 5000,
            'status' => 'PENDING',
            'date' => now(),
            'created_by_id' => $this->admin->getKey(),
        ]);
        $this->forgetTenant();

        $this->getJson('/api/v1/inflows/aging', $this->headers())
            ->assertOk()
            ->assertJsonPath('data.total_outstanding.minor', 10000); // unaffected by the pending inflow
    }

    public function test_fully_paid_order_is_excluded_entirely(): void
    {
        $this->actingAsTenant($this->tenant);
        $paid = $this->makeOrder(10000, Carbon::now()->subDays(5)->toDateTimeString());
        $this->payOrder($paid, 10000);

        // Over-paid: balance goes negative, must also be excluded (not counted as a negative bucket).
        $overpaid = $this->makeOrder(5000, Carbon::now()->subDays(5)->toDateTimeString());
        $this->payOrder($overpaid, 7000);
        $this->forgetTenant();

        $this->getJson('/api/v1/inflows/aging', $this->headers())
            ->assertOk()
            ->assertJsonPath('data.total_outstanding.minor', 0)
            ->assertJsonPath('data.buckets.current.minor', 0)
            ->assertJsonPath('data.buckets.31_60.minor', 0)
            ->assertJsonPath('data.buckets.61_90.minor', 0)
            ->assertJsonPath('data.buckets.90_plus.minor', 0)
            ->assertJsonCount(0, 'data.by_customer');
    }

    public function test_credit_note_reduces_the_paid_amount_increasing_the_balance(): void
    {
        $this->actingAsTenant($this->tenant);
        $order = $this->makeOrder(10000, Carbon::now()->subDays(5)->toDateTimeString());
        $this->payOrder($order, 3000);
        $this->payOrder($order, 1000, isCreditNote: true);
        $this->forgetTenant();

        // paid = 3000 - 1000 = 2000 → balance = 10000 - 2000 = 8000.
        $this->getJson('/api/v1/inflows/aging', $this->headers())
            ->assertOk()
            ->assertJsonPath('data.total_outstanding.minor', 8000);
    }

    public function test_consignment_orders_are_excluded_entirely(): void
    {
        $this->actingAsTenant($this->tenant);
        $this->makeOrder(10000, Carbon::now()->subDays(5)->toDateTimeString(), overrides: ['is_consignment' => true]);
        $this->makeOrder(5000, Carbon::now()->subDays(5)->toDateTimeString());
        $this->forgetTenant();

        $this->getJson('/api/v1/inflows/aging', $this->headers())
            ->assertOk()
            ->assertJsonPath('data.total_outstanding.minor', 5000)
            ->assertJsonPath('data.by_customer.0.orders', 1);
    }

    public function test_effective_date_prefers_backorder_date_over_created_at(): void
    {
        $this->actingAsTenant($this->tenant);
        // created "now" (age 0 → current) but backorder_date 95 days ago → must use backorder_date (d90_plus).
        $this->makeOrder(
            10000,
            Carbon::now()->toDateTimeString(),
            Carbon::now()->subDays(95)->toDateTimeString(),
        );
        $this->forgetTenant();

        $this->getJson('/api/v1/inflows/aging', $this->headers())
            ->assertOk()
            ->assertJsonPath('data.buckets.current.minor', 0)
            ->assertJsonPath('data.buckets.90_plus.minor', 10000);
    }

    public function test_by_customer_breakdown_is_sorted_by_outstanding_descending(): void
    {
        $this->actingAsTenant($this->tenant);
        $big = Customer::create(['company_name' => 'Big Spender', 'email' => 'big@example.com']);
        $small = Customer::create(['company_name' => 'Small Spender', 'email' => 'small@example.com']);

        $this->makeOrder(3000, Carbon::now()->subDays(5)->toDateTimeString(), overrides: ['customer_id' => $small->getKey()]);
        $this->makeOrder(9000, Carbon::now()->subDays(5)->toDateTimeString(), overrides: ['customer_id' => $big->getKey()]);
        $this->makeOrder(1000, Carbon::now()->subDays(5)->toDateTimeString(), overrides: ['customer_id' => $big->getKey()]);
        $this->forgetTenant();

        $this->getJson('/api/v1/inflows/aging', $this->headers())
            ->assertOk()
            ->assertJsonPath('data.by_customer.0.company_name', 'Big Spender')
            ->assertJsonPath('data.by_customer.0.outstanding.minor', 10000)
            ->assertJsonPath('data.by_customer.0.orders', 2)
            ->assertJsonPath('data.by_customer.1.company_name', 'Small Spender')
            ->assertJsonPath('data.by_customer.1.outstanding.minor', 3000)
            ->assertJsonPath('data.by_customer.1.orders', 1);
    }

    public function test_aging_requires_finance_visibility(): void
    {
        $member = $this->createMember($this->tenant, [TenantRole::Inventory]); // no finance.view
        Sanctum::actingAs($member);

        $this->getJson('/api/v1/inflows/aging', $this->headers())->assertForbidden();
    }
}
