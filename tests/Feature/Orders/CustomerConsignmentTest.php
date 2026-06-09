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
}
