<?php

declare(strict_types=1);

namespace Tests\Feature\Orders;

use App\Enums\TenantRole;
use App\Models\Customer;
use App\Models\InventoryItem;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\InteractsWithTenancy;
use Tests\TestCase;

class CustomerConsignmentTest extends TestCase
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
        $this->customer = Customer::create(['company_name' => 'Bar', 'email' => 'b@example.com']);
        $this->wine = InventoryItem::create([
            'name' => 'Plavac', 'sku' => 'PLV', 'category' => 'FINISHED', 'unit' => 'bottles',
            'sales_unit' => 'cases',
            'current_stock' => '100.000', 'bottles_per_case' => 12, 'is_for_sale' => true,
            'default_price' => 1000, 'cost_per_unit' => 400,
        ]);
        $this->forgetTenant();
        Sanctum::actingAs($this->admin);
    }

    /** @return array<string, string> */
    private function headers(): array
    {
        return $this->tenantHeader($this->tenant);
    }

    private function place(): void
    {
        $this->postJson("/api/v1/customers/{$this->customer->getKey()}/consignment/place", [
            'items' => [['inventory_item_id' => $this->wine->getKey(), 'quantity' => 1, 'unit_type' => 'cases']],
        ], $this->headers())->assertCreated();
    }

    public function test_fifo_sale_then_return_across_placements(): void
    {
        $this->place(); // placement 1: 12 bottles
        $this->place(); // placement 2: 12 bottles → 24 outstanding, stock 100-24=76

        $this->getJson("/api/v1/customers/{$this->customer->getKey()}/consignment", $this->headers())
            ->assertOk()
            ->assertJsonPath('data.products.0.placed', 24)
            ->assertJsonPath('data.products.0.remaining', 24);

        // Sell 18 → consumes all of placement 1 (12) + 6 of placement 2 (FIFO).
        $this->postJson("/api/v1/customers/{$this->customer->getKey()}/consignment/sale", [
            'items' => [['inventory_item_id' => $this->wine->getKey(), 'quantity' => 18]],
        ], $this->headers())
            ->assertOk()
            ->assertJsonPath('data.products.0.sold', 18)
            ->assertJsonPath('data.products.0.remaining', 6);

        $this->assertSame('76.000', (string) $this->wine->refresh()->current_stock); // sale doesn't move stock

        // Return the remaining 6 → restocks.
        $this->postJson("/api/v1/customers/{$this->customer->getKey()}/consignment/return", [
            'items' => [['inventory_item_id' => $this->wine->getKey(), 'quantity' => 6]],
        ], $this->headers())
            ->assertOk()
            ->assertJsonPath('data.products.0.remaining', 0);

        $this->assertSame('82.000', (string) $this->wine->refresh()->current_stock); // 76 + 6
    }

    public function test_sale_beyond_outstanding_is_rejected(): void
    {
        $this->place(); // 12 outstanding

        $this->postJson("/api/v1/customers/{$this->customer->getKey()}/consignment/sale", [
            'items' => [['inventory_item_id' => $this->wine->getKey(), 'quantity' => 99]],
        ], $this->headers())->assertStatus(422);
    }

    /**
     * Ahead of eager-loading openLines()'s per-order query and dropping
     * placements()'s redundant re-query, this locks down that: (1) summary()
     * tallies the correct remaining quantity per product across TWO
     * placements and THREE separate sale/return reports, and (2) placements()
     * — built from those same lines — agrees with that tally rather than
     * independently re-deriving a different number for the same order set.
     */
    public function test_summary_and_placements_agree_across_multiple_orders_and_reports(): void
    {
        $this->actingAsTenant($this->tenant);
        $wine2 = InventoryItem::create([
            'name' => 'Babic', 'sku' => 'BAB', 'category' => 'FINISHED', 'unit' => 'bottles',
            'sales_unit' => 'bottles',
            'current_stock' => '50.000', 'is_for_sale' => true,
            'default_price' => 200, 'cost_per_unit' => 80,
        ]);
        $this->forgetTenant();

        // Placement 1 (oldest): 12 bottles of wine + 10 bottles of wine2.
        $this->postJson("/api/v1/customers/{$this->customer->getKey()}/consignment/place", [
            'items' => [
                ['inventory_item_id' => $this->wine->getKey(), 'quantity' => 1, 'unit_type' => 'cases'],
                ['inventory_item_id' => $wine2->getKey(), 'quantity' => 10, 'unit_type' => 'bottles'],
            ],
        ], $this->headers())->assertCreated();

        // Placement 2: 12 bottles of wine + 5 bottles of wine2.
        $this->postJson("/api/v1/customers/{$this->customer->getKey()}/consignment/place", [
            'items' => [
                ['inventory_item_id' => $this->wine->getKey(), 'quantity' => 1, 'unit_type' => 'cases'],
                ['inventory_item_id' => $wine2->getKey(), 'quantity' => 5, 'unit_type' => 'bottles'],
            ],
        ], $this->headers())->assertCreated();

        // 24 wine + 15 wine2 outstanding across the two placements.

        // Report 1 (sale): 8× wine2 — fully within placement 1 (10 placed there).
        $this->postJson("/api/v1/customers/{$this->customer->getKey()}/consignment/sale", [
            'items' => [['inventory_item_id' => $wine2->getKey(), 'quantity' => 8]],
        ], $this->headers())->assertOk();

        // Report 2 (sale): 15× wine — drains placement 1 fully (12) + 3 from placement 2.
        $this->postJson("/api/v1/customers/{$this->customer->getKey()}/consignment/sale", [
            'items' => [['inventory_item_id' => $this->wine->getKey(), 'quantity' => 15]],
        ], $this->headers())->assertOk();

        // Report 3 (return): 3× wine2 — FIFO drains placement 1's remaining 2, then 1 from placement 2.
        $this->postJson("/api/v1/customers/{$this->customer->getKey()}/consignment/return", [
            'items' => [['inventory_item_id' => $wine2->getKey(), 'quantity' => 3]],
        ], $this->headers())->assertOk();

        $summary = $this->getJson("/api/v1/customers/{$this->customer->getKey()}/consignment", $this->headers())
            ->assertOk()->json('data');

        $byProduct = [];
        foreach ($summary['products'] as $p) {
            $byProduct[$p['inventory_item_id']] = $p;
        }

        $this->assertSame(24, $byProduct[$this->wine->getKey()]['placed']);
        $this->assertSame(15, $byProduct[$this->wine->getKey()]['sold']);
        $this->assertSame(0, $byProduct[$this->wine->getKey()]['returned']);
        $this->assertSame(9, $byProduct[$this->wine->getKey()]['remaining']);

        $this->assertSame(15, $byProduct[$wine2->getKey()]['placed']);
        $this->assertSame(8, $byProduct[$wine2->getKey()]['sold']);
        $this->assertSame(3, $byProduct[$wine2->getKey()]['returned']);
        $this->assertSame(4, $byProduct[$wine2->getKey()]['remaining']);

        $this->assertSame(13, $summary['total_remaining']); // 9 + 4

        // placements() must agree with openLines()'s own tally of the same
        // order set — a shared-fetch refactor that drops an order, or that
        // re-derives remaining differently, would break this equality.
        $placements = $summary['placements'];
        $this->assertCount(2, $placements); // both orders remain open (neither closed)
        $this->assertSame(13, array_sum(array_column($placements, 'remaining')));
    }
}
