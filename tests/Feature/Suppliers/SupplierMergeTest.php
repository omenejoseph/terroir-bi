<?php

declare(strict_types=1);

namespace Tests\Feature\Suppliers;

use App\Enums\TenantRole;
use App\Models\Cost;
use App\Models\Supplier;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\InteractsWithTenancy;
use Tests\TestCase;

class SupplierMergeTest extends TestCase
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

    public function test_merge_folds_orders_costs_and_price_lines_into_the_winner(): void
    {
        $this->actingAsTenant($this->tenant);
        $winner = Supplier::create(['company_name' => 'Glassco']);
        $loser = Supplier::create(['company_name' => 'Glass Co.']);

        $winner->priceItems()->create(['description' => 'Cork', 'unit_price' => 25]); // collision
        $loser->priceItems()->create(['description' => 'Cork', 'unit_price' => 30]);   // dropped
        $loser->priceItems()->create(['description' => 'Capsule', 'unit_price' => 40]); // reassigned

        $loser->orders()->create(['order_number' => 'PO-L1', 'status' => 'SENT', 'created_by_id' => $this->admin->getKey()]);
        Cost::create(['date' => '2026-06-01', 'total_amount' => 5000, 'category' => 'Packaging', 'supplier_id' => $loser->getKey(), 'created_by_id' => $this->admin->getKey()]);
        $this->forgetTenant();

        // Preview makes no changes.
        $this->postJson('/api/v1/suppliers/merge/preview', [
            'winner_id' => $winner->getKey(), 'loser_ids' => [$loser->getKey()],
        ], $this->headers())
            ->assertOk()
            ->assertJsonPath('data.applied', false)
            ->assertJsonPath('data.totals.orders', 1)
            ->assertJsonPath('data.totals.costs', 1)
            ->assertJsonPath('data.totals.price_reassign', 1)
            ->assertJsonPath('data.totals.price_drop', 1)
            ->assertJsonPath('data.totals.losers_deleted', 1);
        $this->assertDatabaseHas('suppliers', ['id' => $loser->getKey()]);

        // Apply.
        $this->postJson('/api/v1/suppliers/merge', [
            'winner_id' => $winner->getKey(), 'loser_ids' => [$loser->getKey()],
        ], $this->headers())
            ->assertOk()
            ->assertJsonPath('data.applied', true);

        $this->assertDatabaseMissing('suppliers', ['id' => $loser->getKey()]);
        // Order + cost now belong to the winner; Capsule moved, the duplicate Cork dropped.
        $this->assertDatabaseHas('supplier_orders', ['order_number' => 'PO-L1', 'supplier_id' => $winner->getKey()]);
        $this->assertDatabaseHas('costs', ['supplier_id' => $winner->getKey()]);
        $this->assertSame(2, $winner->priceItems()->count()); // Cork + Capsule
    }

    public function test_merge_rejects_winner_in_losers(): void
    {
        $this->actingAsTenant($this->tenant);
        $a = Supplier::create(['company_name' => 'A']);
        $this->forgetTenant();

        $this->postJson('/api/v1/suppliers/merge/preview', [
            'winner_id' => $a->getKey(), 'loser_ids' => [$a->getKey()],
        ], $this->headers())->assertStatus(422);
    }

    public function test_merge_requires_delete_capability(): void
    {
        $member = $this->createMember($this->tenant, [TenantRole::Manager]); // manage, not delete

        $this->actingAsTenant($this->tenant);
        $a = Supplier::create(['company_name' => 'A']);
        $b = Supplier::create(['company_name' => 'B']);
        $this->forgetTenant();

        Sanctum::actingAs($member);
        // Preview is allowed (manage); applying the merge is not (delete-only).
        $this->postJson('/api/v1/suppliers/merge/preview', [
            'winner_id' => $a->getKey(), 'loser_ids' => [$b->getKey()],
        ], $this->headers())->assertOk();

        $this->postJson('/api/v1/suppliers/merge', [
            'winner_id' => $a->getKey(), 'loser_ids' => [$b->getKey()],
        ], $this->headers())->assertForbidden();
    }
}
