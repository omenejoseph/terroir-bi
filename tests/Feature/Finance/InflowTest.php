<?php

declare(strict_types=1);

namespace Tests\Feature\Finance;

use App\Enums\TenantRole;
use App\Models\Cost;
use App\Models\Customer;
use App\Models\Inflow;
use App\Models\InventoryItem;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\InteractsWithTenancy;
use Tests\TestCase;

class InflowTest extends TestCase
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
            'sales_unit' => 'cases', 'current_stock' => '500.000', 'bottles_per_case' => 12,
            'is_for_sale' => true, 'default_price' => 1000,
        ]);
        $this->forgetTenant();
        Sanctum::actingAs($this->admin);
    }

    /** @return array<string, string> */
    private function headers(): array
    {
        return $this->tenantHeader($this->tenant);
    }

    /** @return array{0: string, 1: string} */
    private function createOrder(): array
    {
        $res = $this->postJson('/api/v1/orders', [
            'customer_id' => $this->customer->getKey(),
            'items' => [['inventory_item_id' => $this->wine->getKey(), 'quantity' => 2, 'unit_type' => 'cases']],
        ], $this->headers())->assertCreated();

        return [$res->json('data.id'), $res->json('data.order_number')];
    }

    public function test_inflow_can_be_tied_to_an_order_and_filtered_by_it(): void
    {
        [$orderId, $orderNumber] = $this->createOrder();

        $id = $this->postJson('/api/v1/inflows', [
            'amount' => 12000, 'order_id' => $orderId, 'customer_id' => $this->customer->getKey(),
        ], $this->headers())
            ->assertCreated()
            ->assertJsonPath('data.order_id', $orderId)
            ->assertJsonPath('data.order_number', $orderNumber)
            ->json('data.id');

        // An untied inflow is excluded when filtering by the order.
        $this->postJson('/api/v1/inflows', ['amount' => 5000], $this->headers())->assertCreated();

        $this->getJson("/api/v1/inflows?order_id={$orderId}", $this->headers())
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $id);
    }

    public function test_editing_an_inflow_records_a_change_history(): void
    {
        $id = $this->postJson('/api/v1/inflows', [
            'amount' => 10000, 'status' => 'PENDING', 'category' => 'Deposit',
        ], $this->headers())->json('data.id');

        // A no-op edit records nothing.
        $this->patchJson("/api/v1/inflows/{$id}", ['category' => 'Deposit'], $this->headers())->assertOk();
        $this->getJson("/api/v1/inflows/{$id}/changes", $this->headers())->assertOk()->assertJsonCount(0, 'data');

        // A real edit (amount + category) records one entry holding both field diffs.
        $this->patchJson("/api/v1/inflows/{$id}", ['amount' => 15000, 'category' => 'Final'], $this->headers())
            ->assertOk()
            ->assertJsonPath('data.amount.minor', 15000)
            ->assertJsonPath('data.changes_count', 1);

        // Diffs come in tracked-field order: amount, then category.
        $this->getJson("/api/v1/inflows/{$id}/changes", $this->headers())
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.changes.0.field', 'amount')
            ->assertJsonPath('data.0.changes.0.old', 10000)
            ->assertJsonPath('data.0.changes.0.new', 15000)
            ->assertJsonPath('data.0.changes.1.field', 'category')
            ->assertJsonPath('data.0.changes.1.old', 'Deposit')
            ->assertJsonPath('data.0.changes.1.new', 'Final')
            ->assertJsonPath('data.0.changed_by', fn ($v) => $v !== null);

        // Status changes (separate endpoint) are tracked too.
        $this->patchJson("/api/v1/inflows/{$id}/status", ['status' => 'RECEIVED'], $this->headers())->assertOk();
        $this->getJson("/api/v1/inflows/{$id}/changes", $this->headers())->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_inflow_analytics_summarises_collected_pending_and_cash_flow(): void
    {
        $this->actingAsTenant($this->tenant);
        // Two invoices: one collected (5 days), one pending.
        Inflow::create(['date' => '2026-06-01', 'amount' => 10000, 'category' => 'Invoice', 'status' => 'RECEIVED', 'received_at' => '2026-06-06', 'created_by_id' => $this->admin->getKey(), 'customer_id' => $this->customer->getKey()]);
        Inflow::create(['date' => '2026-06-03', 'amount' => 6000, 'category' => 'Invoice', 'status' => 'PENDING', 'created_by_id' => $this->admin->getKey(), 'customer_id' => $this->customer->getKey()]);
        Cost::create(['date' => '2026-06-04', 'total_amount' => 4000, 'category' => 'Glass', 'created_by_id' => $this->admin->getKey()]);
        $this->forgetTenant();

        $this->getJson('/api/v1/inflows/analytics?from=2026-06-01&to=2026-06-30', $this->headers())
            ->assertOk()
            ->assertJsonPath('data.invoiced.total.minor', 16000)
            ->assertJsonPath('data.invoiced.count', 2)
            ->assertJsonPath('data.collected.total.minor', 10000)
            ->assertJsonPath('data.collected.count', 1)
            ->assertJsonPath('data.pending.total.minor', 6000)
            ->assertJsonPath('data.net_cash_flow.inflows.minor', 10000)   // collected
            ->assertJsonPath('data.net_cash_flow.costs.minor', 4000)
            ->assertJsonPath('data.net_cash_flow.net.minor', 6000)         // 10000 − 4000
            ->assertJsonPath('data.avg_days_to_collect.days', 5)
            ->assertJsonPath('data.avg_inflow.avg.minor', 8000)
            ->assertJsonCount(1, 'data.by_customer');
    }

    public function test_analytics_endpoint_requires_finance_visibility(): void
    {
        $member = $this->createMember($this->tenant, [TenantRole::Inventory]); // no finance.view

        Sanctum::actingAs($member);
        $this->getJson('/api/v1/inflows/analytics', $this->headers())->assertForbidden();
    }

    public function test_changes_endpoint_requires_finance_visibility(): void
    {
        $member = $this->createMember($this->tenant, [TenantRole::Inventory]); // no finance.view

        $id = $this->postJson('/api/v1/inflows', ['amount' => 1000], $this->headers())->json('data.id');

        Sanctum::actingAs($member);
        $this->getJson("/api/v1/inflows/{$id}/changes", $this->headers())->assertForbidden();
    }
}
