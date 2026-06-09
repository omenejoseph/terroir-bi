<?php

declare(strict_types=1);

namespace Tests\Feature\Dashboard;

use App\Enums\TenantRole;
use App\Models\Customer;
use App\Models\InventoryItem;
use App\Models\WorkOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\InteractsWithTenancy;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use InteractsWithTenancy;
    use RefreshDatabase;

    public function test_summary_returns_real_stats_and_placeholder_series(): void
    {
        $tenant = $this->createTenant();
        $admin = $this->createMember($tenant, [TenantRole::Admin]);
        $this->actingAsTenant($tenant);
        InventoryItem::create([
            'name' => 'Cork', 'sku' => 'C', 'category' => 'RAW_MATERIAL', 'unit' => 'units',
            'current_stock' => '5', 'min_stock' => '20',
        ]);
        $this->forgetTenant();

        Sanctum::actingAs($admin);

        $this->getJson('/api/v1/dashboard?range=30D', $this->tenantHeader($tenant))
            ->assertOk()
            ->assertJsonPath('data.range', '30D')
            ->assertJsonPath('data.stats.low_stock', 1)   // real
            ->assertJsonPath('data.stats.customers', 0)   // real (none created)
            ->assertJsonStructure([
                'data' => [
                    'currency',
                    'stats' => ['total_orders', 'customers', 'revenue', 'low_stock'],
                    'orders' => [['label', 'value']],
                    'revenue' => [['label', 'value']],
                    'order_status' => [['key', 'value']],
                    'stock_watch',
                    'recent_orders',
                ],
            ]);
    }

    public function test_summary_defaults_invalid_range(): void
    {
        $tenant = $this->createTenant();
        $admin = $this->createMember($tenant, [TenantRole::Admin]);

        Sanctum::actingAs($admin);

        $this->getJson('/api/v1/dashboard?range=bogus', $this->tenantHeader($tenant))
            ->assertOk()
            ->assertJsonPath('data.range', '30D');
    }

    public function test_summary_reflects_real_orders_ar_and_tasks(): void
    {
        $tenant = $this->createTenant();
        $admin = $this->createMember($tenant, [TenantRole::Admin]);
        $this->actingAsTenant($tenant);
        $customer = Customer::create(['company_name' => 'Konoba', 'email' => 'k@example.com']);
        $wine = InventoryItem::create([
            'name' => 'Plavac', 'sku' => 'PLV', 'category' => 'FINISHED', 'unit' => 'bottles',
            'sales_unit' => 'cases',
            'current_stock' => '500.000', 'bottles_per_case' => 12, 'is_for_sale' => true, 'default_price' => 12000,
        ]);
        WorkOrder::create(['title' => 'Overdue', 'created_by_id' => $admin->getKey(), 'due_date' => now()->subDay(), 'status' => 'TODO']);
        $this->forgetTenant();

        Sanctum::actingAs($admin);
        $headers = $this->tenantHeader($tenant);

        // One order of 2 cases (total 24000), with a partial payment of 10000.
        $id = $this->postJson('/api/v1/orders', [
            'customer_id' => $customer->getKey(),
            'items' => [['inventory_item_id' => $wine->getKey(), 'quantity' => 2, 'unit_type' => 'cases']],
        ], $headers)->assertCreated()->json('data.id');
        $this->postJson("/api/v1/orders/{$id}/payments", ['amount' => 10000], $headers)->assertCreated();

        $this->getJson('/api/v1/dashboard?range=30D', $headers)
            ->assertOk()
            ->assertJsonPath('data.stats.total_orders', 1)
            ->assertJsonPath('data.stats.customers', 1)
            ->assertJsonPath('data.stats.revenue', 24000)
            ->assertJsonPath('data.stats.outstanding_ar', 14000) // 24000 billed - 10000 received
            ->assertJsonPath('data.stats.tasks_overdue', 1)
            ->assertJsonPath('data.order_status.0', ['key' => 'received', 'value' => 1])
            ->assertJsonPath('data.top_products.0.name', 'Plavac')
            ->assertJsonPath('data.recent_orders.0.customer', 'Konoba')
            ->assertJsonPath('data.recent_orders.0.total', 24000);
    }
}
