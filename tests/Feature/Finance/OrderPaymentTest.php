<?php

declare(strict_types=1);

namespace Tests\Feature\Finance;

use App\Enums\TenantRole;
use App\Models\Customer;
use App\Models\InventoryItem;
use App\Models\Order;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\InteractsWithTenancy;
use Tests\TestCase;

class OrderPaymentTest extends TestCase
{
    use InteractsWithTenancy;
    use RefreshDatabase;

    private Tenant $tenant;

    private User $admin;

    private Customer $customer;

    private InventoryItem $wine;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = $this->createTenant();
        $this->admin = $this->createMember($this->tenant, [TenantRole::Admin]);
        $this->actingAsTenant($this->tenant);
        $this->customer = Customer::create(['company_name' => 'Konoba', 'email' => 'k@example.com']);
        $this->wine = InventoryItem::create([
            'name' => 'Plavac', 'sku' => 'PLV', 'category' => 'FINISHED', 'unit' => 'bottles',
            'sales_unit' => 'cases',
            'current_stock' => '500.000', 'bottles_per_case' => 12, 'is_for_sale' => true, 'default_price' => 12000,
        ]);
        $this->forgetTenant();
        Sanctum::actingAs($this->admin);
    }

    /** @return array<string, string> */
    private function headers(): array
    {
        return $this->tenantHeader($this->tenant);
    }

    private function createOrder(): string
    {
        return $this->postJson('/api/v1/orders', [
            'customer_id' => $this->customer->getKey(),
            'items' => [['inventory_item_id' => $this->wine->getKey(), 'quantity' => 2, 'unit_type' => 'cases']],
        ], $this->headers())->assertCreated()->json('data.id'); // total 24000
    }

    public function test_order_starts_unpaid_then_partial_then_paid(): void
    {
        $id = $this->createOrder();

        $this->getJson("/api/v1/orders/{$id}", $this->headers())
            ->assertOk()
            ->assertJsonPath('data.payment.status', 'UNPAID')
            ->assertJsonPath('data.payment.balance_due.minor', 24000);

        // Partial payment.
        $this->postJson("/api/v1/orders/{$id}/payments", ['amount' => 10000], $this->headers())
            ->assertCreated()
            ->assertJsonPath('data.summary.status', 'PARTIAL')
            ->assertJsonPath('data.summary.amount_paid.minor', 10000)
            ->assertJsonPath('data.summary.balance_due.minor', 14000);

        // Settle the rest.
        $this->postJson("/api/v1/orders/{$id}/payments", ['amount' => 14000], $this->headers())
            ->assertCreated()
            ->assertJsonPath('data.summary.status', 'PAID')
            ->assertJsonPath('data.summary.balance_due.minor', 0);

        $this->getJson("/api/v1/orders/{$id}/payments", $this->headers())
            ->assertOk()
            ->assertJsonCount(2, 'data.payments');
    }

    public function test_credit_note_reduces_amount_paid(): void
    {
        $id = $this->createOrder();
        $this->postJson("/api/v1/orders/{$id}/payments", ['amount' => 24000], $this->headers())
            ->assertJsonPath('data.summary.status', 'PAID');

        $this->postJson("/api/v1/orders/{$id}/payments", ['amount' => 4000, 'is_credit_note' => true], $this->headers())
            ->assertCreated()
            ->assertJsonPath('data.summary.amount_paid.minor', 20000)
            ->assertJsonPath('data.summary.status', 'PARTIAL');
    }

    public function test_pending_inflow_does_not_count_until_received(): void
    {
        $id = $this->createOrder();

        // An expected (PENDING) payment doesn't reduce the balance.
        $inflowId = $this->postJson('/api/v1/inflows', [
            'order_id' => $id, 'customer_id' => $this->customer->getKey(),
            'amount' => 24000, 'status' => 'PENDING', 'due_date' => '2026-08-01',
        ], $this->headers())->assertCreated()->json('data.id');

        $this->getJson("/api/v1/orders/{$id}/payments", $this->headers())
            ->assertJsonPath('data.summary.status', 'UNPAID');

        // Mark it received → now PAID.
        $this->patchJson("/api/v1/inflows/{$inflowId}/status", ['status' => 'RECEIVED'], $this->headers())
            ->assertOk()
            ->assertJsonPath('data.status', 'RECEIVED');

        $this->getJson("/api/v1/orders/{$id}/payments", $this->headers())
            ->assertJsonPath('data.summary.status', 'PAID');
    }

    public function test_ar_aging_reports_outstanding_balances(): void
    {
        $id = $this->createOrder(); // unpaid 24000
        // Age it ~75 days so it lands in the 61–90 bucket.
        $this->actingAsTenant($this->tenant);
        Order::query()->whereKey($id)->update(['created_at' => now()->subDays(75)]);
        $this->forgetTenant();

        $this->getJson('/api/v1/inflows/aging', $this->headers())
            ->assertOk()
            ->assertJsonPath('data.total_outstanding.minor', 24000)
            ->assertJsonPath('data.buckets.61_90.minor', 24000)
            ->assertJsonPath('data.by_customer.0.customer_id', $this->customer->getKey());
    }
}
