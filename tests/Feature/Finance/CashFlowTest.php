<?php

declare(strict_types=1);

namespace Tests\Feature\Finance;

use App\Enums\TenantRole;
use App\Models\Cost;
use App\Models\Inflow;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\InteractsWithTenancy;
use Tests\TestCase;

class CashFlowTest extends TestCase
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
    }

    /** @return array<string, string> */
    private function headers(): array
    {
        return $this->tenantHeader($this->tenant);
    }

    public function test_cash_flow_reports_history_forecast_and_pending(): void
    {
        $this->actingAsTenant($this->tenant);
        // Cash in (received) this month, and an expected (pending) receivable.
        Inflow::create(['amount' => 50000, 'status' => 'RECEIVED', 'date' => now(), 'created_by_id' => $this->admin->getKey()]);
        Inflow::create(['amount' => 20000, 'status' => 'PENDING', 'date' => now(), 'created_by_id' => $this->admin->getKey()]);
        // Cash out this month, and an unpaid payable.
        Cost::create(['total_amount' => 30000, 'category' => 'Glass', 'status' => 'PAID', 'date' => now(), 'created_by_id' => $this->admin->getKey()]);
        Cost::create(['total_amount' => 5000, 'category' => 'Corks', 'status' => 'PENDING', 'date' => now(), 'created_by_id' => $this->admin->getKey()]);
        $this->forgetTenant();

        Sanctum::actingAs($this->admin);

        $response = $this->getJson('/api/v1/cash-flow', $this->headers())->assertOk();

        $response->assertJsonCount(12, 'data.historical')
            ->assertJsonCount(6, 'data.forecast')
            ->assertJsonPath('data.forecast.0.is_projection', true)
            // Pending pipeline: receivable 20000, payable 5000 (the two unpaid).
            ->assertJsonPath('data.pending.receivable.minor', 20000)
            ->assertJsonPath('data.pending.payable.minor', 5000)
            ->assertJsonPath('data.pending.net.minor', 15000);

        // Current month historical net = 50000 in - 35000 out = 15000.
        $historical = (array) $response->json('data.historical');
        $current = end($historical);
        $this->assertSame(15000, $current['net']['minor']);
    }

    public function test_credit_note_reduces_cash_in(): void
    {
        $this->actingAsTenant($this->tenant);
        Inflow::create(['amount' => 50000, 'status' => 'RECEIVED', 'date' => now(), 'created_by_id' => $this->admin->getKey()]);
        Inflow::create(['amount' => 8000, 'status' => 'RECEIVED', 'is_credit_note' => true, 'date' => now(), 'created_by_id' => $this->admin->getKey()]);
        $this->forgetTenant();

        Sanctum::actingAs($this->admin);
        $historical = (array) $this->getJson('/api/v1/cash-flow', $this->headers())->assertOk()->json('data.historical');
        $current = end($historical);
        $this->assertSame(42000, $current['revenue']['minor']); // 50000 - 8000
    }
}
