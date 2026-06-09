<?php

declare(strict_types=1);

namespace Tests\Feature\Dashboard;

use App\Enums\TenantRole;
use App\Models\InventoryItem;
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
}
