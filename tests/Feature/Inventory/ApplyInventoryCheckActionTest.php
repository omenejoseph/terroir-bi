<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Actions\Inventory\ApplyInventoryCheckAction;
use App\Enums\TenantRole;
use App\Models\InventoryCheck;
use App\Models\InventoryItem;
use App\Models\StockMovement;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\InteractsWithTenancy;
use Tests\TestCase;

/**
 * ApplyInventoryCheckAction::execute() — the physical-stocktake apply path.
 * InventoryCheckHistoryTest only exercises the HTTP happy path (one changed
 * line, one unchanged line); these lock down the invariants a refactor of the
 * per-row lockForUpdate loop (e.g. batching it into one whereIn) could
 * silently break: atomicity across multiple rows, computing against live
 * server stock rather than a client-supplied figure, skipping zero-diff
 * rows, and flagging the resulting movement as a reconciliation.
 */
class ApplyInventoryCheckActionTest extends TestCase
{
    use InteractsWithTenancy;
    use RefreshDatabase;

    private Tenant $tenant;

    private User $admin;

    private InventoryItem $a;

    private InventoryItem $b;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = $this->createTenant();
        $this->admin = $this->createMember($this->tenant, [TenantRole::Admin]);
        $this->actingAsTenant($this->tenant);
        $this->a = InventoryItem::create(['name' => 'A', 'sku' => 'SKU-A', 'category' => 'FINISHED', 'unit' => 'bottles', 'current_stock' => '100.000']);
        $this->b = InventoryItem::create(['name' => 'B', 'sku' => 'SKU-B', 'category' => 'FINISHED', 'unit' => 'bottles', 'current_stock' => '50.000']);
        $this->forgetTenant();
    }

    /** @return array<string, string> */
    private function headers(): array
    {
        return $this->tenantHeader($this->tenant);
    }

    public function test_multiple_counted_items_are_all_applied_in_one_call(): void
    {
        $this->actingAsTenant($this->tenant);
        $action = app(ApplyInventoryCheckAction::class);

        $results = $action->execute([
            ['item_id' => $this->a->getKey(), 'physical_count' => '90'],  // -10
            ['item_id' => $this->b->getKey(), 'physical_count' => '55'],  // +5
        ], $this->admin);

        self::assertSame($this->a->getKey(), $results[0]['item_id']);
        self::assertSame('-10.000', $results[0]['difference']);
        self::assertSame($this->b->getKey(), $results[1]['item_id']);
        self::assertSame('5.000', $results[1]['difference']);

        self::assertSame('90.000', (string) $this->a->refresh()->current_stock);
        self::assertSame('55.000', (string) $this->b->refresh()->current_stock);
        self::assertSame(2, StockMovement::query()->count());

        $check = InventoryCheck::query()->firstOrFail();
        self::assertSame(2, $check->items_counted);
        self::assertSame(2, $check->items_adjusted);
        self::assertSame('-5.000', (string) $check->net_difference); // -10 + 5
        $this->forgetTenant();
    }

    public function test_a_nonexistent_item_id_rolls_back_the_whole_transaction(): void
    {
        $this->actingAsTenant($this->tenant);
        $action = app(ApplyInventoryCheckAction::class);

        $missingId = (string) Str::ulid();

        try {
            $action->execute([
                // Would adjust -10 (and be committed first) if the transaction
                // were not atomic.
                ['item_id' => $this->a->getKey(), 'physical_count' => '90'],
                ['item_id' => $missingId, 'physical_count' => '10'],
            ], $this->admin);
            self::fail('Expected a ModelNotFoundException for the nonexistent item.');
        } catch (ModelNotFoundException) {
            // Expected — firstOrFail() on the missing row aborts the loop.
        }

        // Nothing from the earlier, valid row was left committed.
        self::assertSame('100.000', (string) $this->a->refresh()->current_stock);
        self::assertSame(0, StockMovement::query()->count());
        self::assertSame(0, InventoryCheck::query()->count());
        $this->forgetTenant();
    }

    public function test_a_zero_difference_row_produces_no_movement_and_leaves_stock_untouched(): void
    {
        $this->actingAsTenant($this->tenant);
        $action = app(ApplyInventoryCheckAction::class);

        $results = $action->execute([
            ['item_id' => $this->a->getKey(), 'physical_count' => '100'], // matches current stock exactly
        ], $this->admin);

        self::assertSame('0.000', $results[0]['difference']);
        self::assertSame('100.000', (string) $this->a->refresh()->current_stock);
        self::assertSame(0, StockMovement::query()->count());
        // No lines changed, so no audit check record is written either.
        self::assertSame(0, InventoryCheck::query()->count());
        $this->forgetTenant();
    }

    public function test_a_nonzero_difference_writes_a_reconciliation_flagged_adjustment_movement(): void
    {
        $this->actingAsTenant($this->tenant);
        $action = app(ApplyInventoryCheckAction::class);

        $action->execute([
            ['item_id' => $this->a->getKey(), 'physical_count' => '90'],
        ], $this->admin);

        $movement = StockMovement::query()->firstOrFail();
        self::assertTrue($movement->is_reconciliation);
        self::assertSame('ADJUSTMENT', $movement->type->value);
        self::assertSame('-10.000', (string) $movement->quantity);
        $this->forgetTenant();
    }

    public function test_difference_is_computed_against_live_server_stock_not_a_client_supplied_figure(): void
    {
        // The item's real stock is 100. A caller lying about what it thinks
        // the system count already is (system_stock) must not change the
        // computed difference or suppress the resulting movement — the
        // server always re-reads current_stock itself.
        Sanctum::actingAs($this->admin);
        $res = $this->postJson('/api/v1/inventory-items/check', [
            'items' => [
                ['item_id' => $this->a->getKey(), 'physical_count' => '90', 'system_stock' => '90'],
            ],
        ], $this->headers())->assertOk();

        $res->assertJsonPath('data.0.difference', '-10.000');

        $this->actingAsTenant($this->tenant);
        self::assertSame('90.000', (string) $this->a->refresh()->current_stock);
        self::assertSame(1, StockMovement::query()->count());
        $this->forgetTenant();
    }

    public function test_check_requires_inventory_manage(): void
    {
        $member = $this->createMember($this->tenant, [TenantRole::WineClub]);
        Sanctum::actingAs($member);
        $this->postJson('/api/v1/inventory-items/check', [
            'items' => [['item_id' => $this->a->getKey(), 'physical_count' => '90']],
        ], $this->headers())->assertForbidden();
    }
}
