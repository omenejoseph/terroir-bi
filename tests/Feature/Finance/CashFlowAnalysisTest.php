<?php

declare(strict_types=1);

namespace Tests\Feature\Finance;

use App\Enums\TenantRole;
use App\Models\Cost;
use App\Models\Customer;
use App\Models\Inflow;
use App\Models\Supplier;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\InteractsWithTenancy;
use Tests\TestCase;

class CashFlowAnalysisTest extends TestCase
{
    use InteractsWithTenancy;
    use RefreshDatabase;

    private Tenant $tenant;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = $this->createTenant();
        $this->admin = $this->createMember($this->tenant, [TenantRole::Admin]);
        Sanctum::actingAs($this->admin);
    }

    /** @return array<string, string> */
    private function headers(): array
    {
        return $this->tenantHeader($this->tenant);
    }

    public function test_analysis_reports_movement_kpis_outstanding_and_discipline(): void
    {
        $this->actingAsTenant($this->tenant);
        $customer = Customer::create(['company_name' => 'Konzum', 'email' => 'konzum@example.com']);
        $supplier = Supplier::create(['company_name' => 'Staklo']);

        // Cash movement inside the window.
        Inflow::create(['date' => '2026-06-10', 'amount' => 10000, 'status' => 'RECEIVED', 'received_at' => '2026-06-15', 'due_date' => '2026-06-20', 'category' => 'Wine sales', 'customer_id' => $customer->getKey(), 'created_by_id' => $this->admin->getKey()]);
        Cost::create(['date' => '2026-06-12', 'total_amount' => 4000, 'status' => 'PAID', 'paid_at' => '2026-06-14', 'due_date' => '2026-06-20', 'category' => 'Glass', 'supplier_id' => $supplier->getKey(), 'created_by_id' => $this->admin->getKey()]);

        // Outstanding snapshot (no date filter): a pending receivable + an unpaid payable.
        Inflow::create(['date' => '2026-06-05', 'amount' => 8000, 'status' => 'PENDING', 'due_date' => '2026-06-01', 'category' => 'Invoice', 'customer_id' => $customer->getKey(), 'created_by_id' => $this->admin->getKey()]);
        Cost::create(['date' => '2026-06-08', 'total_amount' => 3000, 'status' => 'PENDING', 'category' => 'Operations', 'supplier_id' => $supplier->getKey(), 'created_by_id' => $this->admin->getKey()]);
        $this->forgetTenant();

        $this->getJson('/api/v1/cash-flow/analysis?from=2026-06-01&to=2026-06-30', $this->headers())
            ->assertOk()
            ->assertJsonPath('data.cash_in.total.minor', 10000)
            ->assertJsonPath('data.cash_in.count', 1)
            ->assertJsonPath('data.cash_out.total.minor', 4000)
            ->assertJsonPath('data.net.total.minor', 6000)
            ->assertJsonPath('data.burn_rate_monthly.minor', 4000) // one-month window
            ->assertJsonPath('data.outstanding_receivables.total.minor', 8000)
            ->assertJsonPath('data.outstanding_receivables.count', 1)
            ->assertJsonPath('data.outstanding_payables.total.minor', 3000)
            ->assertJsonPath('data.net_working_capital.minor', 5000) // 8000 − 3000
            // Discipline: collected 5 days after date, on time; paid 2 days after, on time.
            ->assertJsonPath('data.payment_discipline.avg_days_to_collect', 5)
            ->assertJsonPath('data.payment_discipline.collected_on_time_percent', 100)
            ->assertJsonPath('data.payment_discipline.avg_days_to_pay', 2)
            ->assertJsonPath('data.payment_discipline.paid_on_time_percent', 100)
            // Top tables expose the counterparty + overdue flag.
            ->assertJsonPath('data.top_receivables.0.counterparty', 'Konzum')
            ->assertJsonPath('data.top_receivables.0.is_overdue', true)
            ->assertJsonPath('data.top_payables.0.counterparty', 'Staklo')
            ->assertJsonPath('data.top_outflow_categories.0.category', 'Glass')
            ->assertJsonPath('data.top_outflow_categories.0.amount.minor', 4000);
    }

    public function test_movement_trend_compares_against_the_preceding_window(): void
    {
        $this->actingAsTenant($this->tenant);
        // Prior window (May): received 4.000.
        Inflow::create(['date' => '2026-05-10', 'amount' => 4000, 'status' => 'RECEIVED', 'received_at' => '2026-05-10', 'created_by_id' => $this->admin->getKey()]);
        // Current window (June): received 10.000 → +150%.
        Inflow::create(['date' => '2026-06-10', 'amount' => 10000, 'status' => 'RECEIVED', 'received_at' => '2026-06-10', 'created_by_id' => $this->admin->getKey()]);
        $this->forgetTenant();

        $this->getJson('/api/v1/cash-flow/analysis?from=2026-06-01&to=2026-06-30', $this->headers())
            ->assertOk()
            ->assertJsonPath('data.cash_in.previous.minor', 4000)
            ->assertJsonPath('data.cash_in.change', 150);
    }

    public function test_analysis_requires_finance_visibility(): void
    {
        $member = $this->createMember($this->tenant, [TenantRole::Inventory]); // no finance.view
        Sanctum::actingAs($member);

        $this->getJson('/api/v1/cash-flow/analysis', $this->headers())->assertForbidden();
    }
}
