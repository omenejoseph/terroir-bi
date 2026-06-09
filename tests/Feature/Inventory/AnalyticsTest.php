<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Enums\TenantRole;
use App\Models\InventoryItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\InteractsWithTenancy;
use Tests\TestCase;

class AnalyticsTest extends TestCase
{
    use InteractsWithTenancy;
    use RefreshDatabase;

    public function test_analytics_reports_value_by_category_and_low_stock(): void
    {
        $tenant = $this->createTenant();
        $admin = $this->createMember($tenant, [TenantRole::Admin]);
        $this->actingAsTenant($tenant);
        // Value: 10 units * €10.00 = €100.00 (10000 minor).
        InventoryItem::create([
            'name' => 'Wine', 'sku' => 'W', 'category' => 'FINISHED', 'unit' => 'bottles',
            'current_stock' => '10', 'default_price' => 10000,
        ]);
        // Below minimum (5 < 20).
        InventoryItem::create([
            'name' => 'Cork', 'sku' => 'C', 'category' => 'RAW_MATERIAL', 'unit' => 'units',
            'current_stock' => '5', 'min_stock' => '20',
        ]);
        // Approaching minimum (12 >= 10 but < 15).
        InventoryItem::create([
            'name' => 'Label', 'sku' => 'L', 'category' => 'RAW_MATERIAL', 'unit' => 'units',
            'current_stock' => '12', 'min_stock' => '10',
        ]);
        $this->forgetTenant();

        Sanctum::actingAs($admin);

        $this->getJson('/api/v1/inventory-items/analytics', $this->tenantHeader($tenant))
            ->assertOk()
            ->assertJsonPath('data.value.total', 100000) // 10 * 10000
            ->assertJsonPath('data.low_stock.below.0.name', 'Cork')
            ->assertJsonPath('data.low_stock.approaching.0.name', 'Label')
            ->assertJsonCount(1, 'data.low_stock.below')
            ->assertJsonCount(1, 'data.low_stock.approaching');
    }
}