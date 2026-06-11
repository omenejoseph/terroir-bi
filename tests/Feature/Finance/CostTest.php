<?php

declare(strict_types=1);

namespace Tests\Feature\Finance;

use App\Enums\TenantRole;
use App\Models\Supplier;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\InteractsWithTenancy;
use Tests\TestCase;

class CostTest extends TestCase
{
    use InteractsWithTenancy;
    use RefreshDatabase;

    private Tenant $tenant;

    private User $admin;

    private Supplier $supplier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = $this->createTenant();
        $this->admin = $this->createMember($this->tenant, [TenantRole::Admin]);
        $this->actingAsTenant($this->tenant);
        $this->supplier = Supplier::create(['company_name' => 'Staklo']);
        $this->forgetTenant();
        Sanctum::actingAs($this->admin);
    }

    /** @return array<string, string> */
    private function headers(): array
    {
        return $this->tenantHeader($this->tenant);
    }

    public function test_create_cost_with_items_and_status_lifecycle(): void
    {
        $id = $this->postJson('/api/v1/costs', [
            'total_amount' => 5000, 'category' => 'Glass', 'supplier_id' => $this->supplier->getKey(),
            'items' => [
                ['description' => 'Bottles 0.75', 'unit_price' => 100, 'quantity' => 30],
            ],
        ], $this->headers())
            ->assertCreated()
            ->assertJsonPath('data.status', 'PENDING')
            ->assertJsonPath('data.items.0.total.minor', 3000) // 100 × 30
            ->json('data.id');

        $this->patchJson("/api/v1/costs/{$id}/status", ['status' => 'PAID'], $this->headers())
            ->assertOk()
            ->assertJsonPath('data.status', 'PAID')
            ->assertJsonPath('data.paid_at', fn ($v) => $v !== null);

        $this->getJson('/api/v1/costs?status=PAID', $this->headers())
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->getJson('/api/v1/costs/categories', $this->headers())
            ->assertOk()
            ->assertJsonFragment(['Glass']);
    }

    public function test_group_filters_and_counts_split_by_category(): void
    {
        $this->postJson('/api/v1/costs', ['total_amount' => 1000, 'category' => 'Invoice', 'date' => '2026-06-05'], $this->headers())->assertCreated();
        $this->postJson('/api/v1/costs', ['total_amount' => 2000, 'category' => 'Payment', 'date' => '2026-06-06'], $this->headers())->assertCreated();
        $this->postJson('/api/v1/costs', ['total_amount' => 3000, 'category' => 'Glass', 'date' => '2026-06-07'], $this->headers())->assertCreated();
        $this->postJson('/api/v1/costs', ['total_amount' => 4000, 'category' => 'Corks', 'date' => '2026-01-01'], $this->headers())->assertCreated();

        // Group filter narrows the list.
        $this->getJson('/api/v1/costs?group=invoices', $this->headers())->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/costs?group=others', $this->headers())->assertOk()->assertJsonCount(2, 'data'); // Glass + Corks

        // Counts split by category group.
        $this->getJson('/api/v1/costs/group-counts', $this->headers())
            ->assertOk()
            ->assertJsonPath('data.all', 4)
            ->assertJsonPath('data.invoices', 1)
            ->assertJsonPath('data.payments', 1)
            ->assertJsonPath('data.others', 2);

        // Date range filters both the list and the counts (excludes the January cost).
        $this->getJson('/api/v1/costs?date_from=2026-06-01&date_to=2026-06-30', $this->headers())
            ->assertOk()->assertJsonCount(3, 'data');
        $this->getJson('/api/v1/costs/group-counts?date_from=2026-06-01&date_to=2026-06-30', $this->headers())
            ->assertOk()->assertJsonPath('data.all', 3)->assertJsonPath('data.others', 1); // only Glass

        // Categories always offer Invoice + Payment.
        $this->getJson('/api/v1/costs/categories', $this->headers())
            ->assertOk()
            ->assertJsonFragment(['Invoice'])
            ->assertJsonFragment(['Payment']);
    }

    public function test_cost_analytics_summarises_spend_and_unpaid(): void
    {
        $this->postJson('/api/v1/costs', ['total_amount' => 5000, 'category' => 'Glass', 'supplier_id' => $this->supplier->getKey()], $this->headers())->assertCreated();
        $this->postJson('/api/v1/costs', ['total_amount' => 2000, 'category' => 'Corks', 'status' => 'PAID'], $this->headers())->assertCreated();

        $this->getJson('/api/v1/costs/analytics?period=all', $this->headers())
            ->assertOk()
            ->assertJsonPath('data.total_spend.minor', 7000)
            ->assertJsonPath('data.unpaid.minor', 5000) // only the PENDING one
            ->assertJsonCount(2, 'data.by_category');
    }
}
