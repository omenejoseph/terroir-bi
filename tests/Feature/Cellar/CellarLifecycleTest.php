<?php

declare(strict_types=1);

namespace Tests\Feature\Cellar;

use App\Enums\TenantRole;
use App\Models\EnologicalProduct;
use App\Models\InventoryItem;
use App\Models\Vessel;
use App\Models\VesselLot;
use App\Models\WineLot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\InteractsWithTenancy;
use Tests\TestCase;

class CellarLifecycleTest extends TestCase
{
    use InteractsWithTenancy;
    use RefreshDatabase;

    private function lotInVessel(string $lotNumber, string $volume, ?Vessel $vessel = null): WineLot
    {
        $lot = WineLot::create([
            'lot_number' => $lotNumber, 'name' => 'Lot '.$lotNumber, 'grape_variety' => 'Plavac',
            'vintage' => '2026', 'wine_type' => 'RED', 'initial_volume' => $volume, 'current_volume' => $volume,
        ]);
        if ($vessel !== null) {
            VesselLot::create(['vessel_id' => $vessel->getKey(), 'wine_lot_id' => $lot->getKey(), 'volume' => $volume]);
            $vessel->forceFill(['current_volume' => $volume, 'status' => 'IN_USE'])->save();
        }

        return $lot;
    }

    public function test_analysis_appears_in_detail_and_trend(): void
    {
        $tenant = $this->createTenant();
        $admin = $this->createMember($tenant, [TenantRole::Admin]);
        $this->actingAsTenant($tenant);
        $lot = $this->lotInVessel('LOT-2026-001', '500.000');
        $this->forgetTenant();

        Sanctum::actingAs($admin);

        $this->postJson("/api/v1/wine-lots/{$lot->getKey()}/analyses", [
            'ph' => 3.45, 'free_so2' => 25, 'total_so2' => 80, 'brix' => 12.5,
        ], $this->tenantHeader($tenant))
            ->assertCreated()
            ->assertJsonPath('data.analyses.0.ph', '3.45')
            ->assertJsonPath('data.analyses.0.free_so2', '25.00');

        $this->getJson("/api/v1/wine-lots/{$lot->getKey()}/analyses/trend", $this->tenantHeader($tenant))
            ->assertOk()
            ->assertJsoncount(1, 'data')
            ->assertJsonPath('data.0.brix', 12.5);
    }

    public function test_addition_deducts_then_restores_enological_stock(): void
    {
        $tenant = $this->createTenant();
        $admin = $this->createMember($tenant, [TenantRole::Admin]);
        $this->actingAsTenant($tenant);
        $lot = $this->lotInVessel('LOT-2026-002', '500.000');
        $product = EnologicalProduct::create([
            'name' => 'K2S2O5', 'category' => 'SO2', 'unit' => 'g', 'current_stock' => '1000.000',
        ]);
        $this->forgetTenant();

        Sanctum::actingAs($admin);

        $this->postJson("/api/v1/wine-lots/{$lot->getKey()}/additions", [
            'name' => 'SO2', 'quantity' => 50, 'unit' => 'g', 'enological_product_id' => $product->getKey(),
        ], $this->tenantHeader($tenant))->assertCreated();

        $this->assertSame('950.000', (string) $product->refresh()->current_stock);

        $additionId = $lot->additions()->first()?->getKey();
        $this->deleteJson("/api/v1/wine-lots/{$lot->getKey()}/additions/{$additionId}", [], $this->tenantHeader($tenant))
            ->assertOk();

        $this->assertSame('1000.000', (string) $product->refresh()->current_stock);
    }

    public function test_bottling_draws_volume_writes_stock_and_frees_vessel(): void
    {
        $tenant = $this->createTenant();
        $admin = $this->createMember($tenant, [TenantRole::Admin]);
        $this->actingAsTenant($tenant);
        $vessel = Vessel::create(['name' => 'T1', 'type' => 'TANK', 'capacity_liters' => '1000.000']);
        $lot = $this->lotInVessel('LOT-2026-003', '750.000', $vessel);
        $item = InventoryItem::create([
            'name' => 'Plavac 2026', 'sku' => 'PLV26', 'category' => 'FINISHED', 'unit' => 'bottles', 'current_stock' => '0',
        ]);
        $this->forgetTenant();

        Sanctum::actingAs($admin);

        // 1000 bottles × 750 ml = 750 L → empties the lot.
        $this->postJson("/api/v1/wine-lots/{$lot->getKey()}/bottlings", [
            'bottle_count' => 1000, 'bottle_volume_ml' => 750, 'inventory_item_id' => $item->getKey(),
        ], $this->tenantHeader($tenant))
            ->assertCreated()
            ->assertJsonPath('data.status', 'BOTTLED')
            ->assertJsonPath('data.current_volume', '0.000');

        $this->assertSame('1000.000', (string) $item->refresh()->current_stock);
        $this->assertSame('0.000', (string) $vessel->refresh()->current_volume);
        $this->assertSame('AVAILABLE', $vessel->status->value);
    }

    public function test_blend_transfer_moves_volume_between_lots(): void
    {
        $tenant = $this->createTenant();
        $admin = $this->createMember($tenant, [TenantRole::Admin]);
        $this->actingAsTenant($tenant);
        $vesselA = Vessel::create(['name' => 'A', 'type' => 'TANK', 'capacity_liters' => '1000.000']);
        $vesselB = Vessel::create(['name' => 'B', 'type' => 'TANK', 'capacity_liters' => '1000.000']);
        $lotA = $this->lotInVessel('LOT-2026-004', '300.000', $vesselA);
        $lotB = $this->lotInVessel('LOT-2026-005', '200.000', $vesselB);
        $this->forgetTenant();

        Sanctum::actingAs($admin);

        $this->postJson("/api/v1/wine-lots/{$lotA->getKey()}/transfers", [
            'type' => 'BLEND', 'to_lot_id' => $lotB->getKey(), 'volume_liters' => 100,
            'to_vessel_id' => $vesselB->getKey(),
        ], $this->tenantHeader($tenant))->assertCreated();

        $this->assertSame('200.000', (string) $lotA->refresh()->current_volume);
        $this->assertSame('300.000', (string) $lotB->refresh()->current_volume);
    }

    public function test_fermentation_monitor_flags_high_volatile_acidity(): void
    {
        $tenant = $this->createTenant();
        $admin = $this->createMember($tenant, [TenantRole::Admin]);
        $this->actingAsTenant($tenant);
        $lot = $this->lotInVessel('LOT-2026-006', '500.000');
        $lot->analyses()->create([
            'created_by_id' => $admin->getKey(), 'date' => now(), 'volatile_acidity' => '0.900',
        ]);
        $this->forgetTenant();

        Sanctum::actingAs($admin);

        $this->getJson('/api/v1/cellar/fermentation-monitor', $this->tenantHeader($tenant))
            ->assertOk()
            ->assertJsonPath('data.0.type', 'volatile_acidity')
            ->assertJsonPath('data.0.severity', 'critical');
    }

    public function test_enological_product_crud_and_stock_adjust(): void
    {
        $tenant = $this->createTenant();
        $admin = $this->createMember($tenant, [TenantRole::Admin]);
        Sanctum::actingAs($admin);

        $id = $this->postJson('/api/v1/enological-products', [
            'name' => 'DAP', 'category' => 'Nutrient', 'unit' => 'g', 'current_stock' => 500,
        ], $this->tenantHeader($tenant))->assertCreated()->json('data.id');

        $this->postJson("/api/v1/enological-products/{$id}/adjust-stock", [
            'delta' => -200,
        ], $this->tenantHeader($tenant))
            ->assertOk()
            ->assertJsonPath('data.current_stock', '300.000');
    }
}
