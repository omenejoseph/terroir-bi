<?php

declare(strict_types=1);

namespace Tests\Feature\Orders;

use App\Enums\TenantRole;
use App\Models\Customer;
use App\Models\CustomerProductOverride;
use App\Models\InventoryItem;
use App\Models\Order;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithTenancy;
use Tests\TestCase;

class PublicOrderTest extends TestCase
{
    use InteractsWithTenancy;
    use RefreshDatabase;

    private Tenant $tenant;

    private Customer $customer;

    private InventoryItem $wine;

    private string $adminId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = $this->createTenant();
        $this->adminId = $this->createMember($this->tenant, [TenantRole::Admin])->getKey(); // system user
        $this->actingAsTenant($this->tenant);
        $this->customer = Customer::create([
            'company_name' => 'Bar Mol', 'email' => 'bar@example.com', 'allow_single_bottle' => true,
        ]);
        $this->customer->order_token = 'TOKEN-ABC-123';
        $this->customer->save();
        $this->wine = InventoryItem::create([
            'name' => 'Plavac', 'sku' => 'PLV', 'category' => 'FINISHED', 'unit' => 'bottles',
            'current_stock' => '100.000', 'bottles_per_case' => 12, 'is_for_sale' => true, 'default_price' => 1000,
        ]);
        $this->forgetTenant();
    }

    public function test_catalog_lists_priced_products(): void
    {
        $this->getJson('/api/v1/public/TOKEN-ABC-123/catalog')
            ->assertOk()
            ->assertJsonPath('data.customer.company_name', 'Bar Mol')
            ->assertJsonPath('data.customer.allow_single_bottle', true)
            ->assertJsonCount(1, 'data.products')
            ->assertJsonPath('data.products.0.price.minor', 1000);
    }

    public function test_unknown_token_is_404(): void
    {
        $this->getJson('/api/v1/public/NOPE/catalog')->assertNotFound();
    }

    public function test_catalog_respects_hide_prices_and_overrides(): void
    {
        $this->actingAsTenant($this->tenant);
        $this->customer->update(['hide_prices' => true]);
        $hidden = InventoryItem::create([
            'name' => 'Secret', 'sku' => 'SEC', 'category' => 'FINISHED', 'unit' => 'bottles',
            'is_for_sale' => true, 'default_price' => 500, 'hide_from_portal' => true,
        ]);
        // Force-show the otherwise-hidden item for this customer.
        CustomerProductOverride::create([
            'customer_id' => $this->customer->getKey(), 'inventory_item_id' => $hidden->getKey(), 'visible' => true,
        ]);
        $this->forgetTenant();

        $response = $this->getJson('/api/v1/public/TOKEN-ABC-123/catalog')->assertOk();
        $response->assertJsonCount(2, 'data.products');
        $response->assertJsonMissingPath('data.products.0.price'); // hide_prices on
    }

    public function test_customer_can_place_an_order_and_stock_is_deducted(): void
    {
        $this->postJson('/api/v1/public/TOKEN-ABC-123/orders', [
            'items' => [['inventory_item_id' => $this->wine->getKey(), 'quantity' => 6, 'unit_type' => 'bottles']],
            'notes' => 'Leave at the back door',
        ])->assertCreated()->assertJsonStructure(['data' => ['order_number']]);

        $this->assertSame('94.000', (string) $this->wine->refresh()->current_stock);

        // Attributed to the tenant's admin (system) user, not the customer.
        $order = Order::withoutTenant()->firstOrFail();
        $this->assertSame($this->adminId, $order->created_by_id);
    }

    public function test_price_tampering_is_rejected(): void
    {
        $this->postJson('/api/v1/public/TOKEN-ABC-123/orders', [
            'items' => [[
                'inventory_item_id' => $this->wine->getKey(),
                'quantity' => 1, 'unit_type' => 'bottles', 'unit_price' => 1, // real is 1000
            ]],
        ])->assertStatus(422);

        $this->assertDatabaseCount('orders', 0);
    }
}
