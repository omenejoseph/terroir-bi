<?php

declare(strict_types=1);

namespace Tests\Feature\Customers;

use App\Enums\TenantRole;
use App\Models\Customer;
use App\Models\CustomerPrice;
use App\Models\CustomerProductOverride;
use App\Models\InventoryItem;
use App\Models\Order;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\InteractsWithTenancy;
use Tests\TestCase;

class CustomerMergeTest extends TestCase
{
    use InteractsWithTenancy;
    use RefreshDatabase;

    private Tenant $tenant;

    private User $admin;

    private Customer $winner;

    private Customer $loser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = $this->createTenant();
        $this->admin = $this->createMember($this->tenant, [TenantRole::Admin]);

        $this->actingAsTenant($this->tenant);
        $this->winner = Customer::create(['company_name' => 'Keep Co', 'email' => 'keep@example.com']);
        $this->loser = Customer::create(['company_name' => 'Dupe Co', 'email' => 'dupe@example.com']);

        $a = InventoryItem::create(['name' => 'A', 'sku' => 'A', 'category' => 'FINISHED', 'unit' => 'bottles']);
        $b = InventoryItem::create(['name' => 'B', 'sku' => 'B', 'category' => 'FINISHED', 'unit' => 'bottles']);
        $c = InventoryItem::create(['name' => 'C', 'sku' => 'C', 'category' => 'FINISHED', 'unit' => 'bottles']);

        // Winner already prices A; loser prices A (collision → drop) and B (reassign).
        CustomerPrice::create(['inventory_item_id' => $a->getKey(), 'customer_id' => $this->winner->getKey(), 'price' => 1000]);
        CustomerPrice::create(['inventory_item_id' => $a->getKey(), 'customer_id' => $this->loser->getKey(), 'price' => 900]);
        CustomerPrice::create(['inventory_item_id' => $b->getKey(), 'customer_id' => $this->loser->getKey(), 'price' => 800]);
        CustomerProductOverride::create(['customer_id' => $this->loser->getKey(), 'inventory_item_id' => $c->getKey(), 'visible' => false]);

        Order::create(['order_number' => 'ORD-1', 'customer_id' => $this->loser->getKey(), 'created_by_id' => $this->admin->getKey(), 'total_amount' => 5000]);
        Order::create(['order_number' => 'ORD-2', 'customer_id' => $this->loser->getKey(), 'created_by_id' => $this->admin->getKey(), 'total_amount' => 7000]);
        $this->forgetTenant();
    }

    /** @return array<string, string> */
    private function headers(): array
    {
        return $this->tenantHeader($this->tenant);
    }

    public function test_preview_reports_without_changing_anything(): void
    {
        Sanctum::actingAs($this->admin);

        $this->postJson('/api/v1/customers/merge/preview', [
            'winner_id' => $this->winner->getKey(),
            'loser_ids' => [$this->loser->getKey()],
        ], $this->headers())
            ->assertOk()
            ->assertJsonPath('data.applied', false)
            ->assertJsonPath('data.totals.orders', 2)
            ->assertJsonPath('data.totals.price_reassign', 1)
            ->assertJsonPath('data.totals.price_drop', 1)
            ->assertJsonPath('data.totals.override_reassign', 1);

        $this->assertDatabaseHas('customers', ['id' => $this->loser->getKey()]); // untouched
    }

    public function test_merge_reassigns_children_drops_collisions_and_deletes_losers(): void
    {
        Sanctum::actingAs($this->admin);

        $this->postJson('/api/v1/customers/merge', [
            'winner_id' => $this->winner->getKey(),
            'loser_ids' => [$this->loser->getKey()],
        ], $this->headers())
            ->assertOk()
            ->assertJsonPath('data.applied', true)
            ->assertJsonPath('data.totals.losers_deleted', 1);

        $this->assertDatabaseMissing('customers', ['id' => $this->loser->getKey()]);
        $this->assertSame(2, Order::withoutTenant()->where('customer_id', $this->winner->getKey())->count());
        // Winner keeps A (1000) + gains B; loser's A price (900) dropped.
        $this->assertSame(2, CustomerPrice::withoutTenant()->where('customer_id', $this->winner->getKey())->count());
        $this->assertSame(0, CustomerPrice::withoutTenant()->where('customer_id', $this->loser->getKey())->count());
        $this->assertSame(1, CustomerProductOverride::withoutTenant()->where('customer_id', $this->winner->getKey())->count());
    }

    public function test_winner_cannot_be_a_loser(): void
    {
        Sanctum::actingAs($this->admin);

        $this->postJson('/api/v1/customers/merge', [
            'winner_id' => $this->winner->getKey(),
            'loser_ids' => [$this->winner->getKey()],
        ], $this->headers())->assertStatus(422);
    }
}
