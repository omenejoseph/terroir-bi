<?php

declare(strict_types=1);

namespace Tests\Feature\Cellar;

use App\Enums\TenantRole;
use App\Models\EnologicalProduct;
use App\Models\Vessel;
use App\Models\VesselLot;
use App\Models\WineLot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\InteractsWithTenancy;
use Tests\TestCase;

class CellarReportsTest extends TestCase
{
    use InteractsWithTenancy;
    use RefreshDatabase;

    private function lotInVessel(string $lotNumber, string $volume, Vessel $vessel, string $variety = 'Plavac'): WineLot
    {
        $lot = WineLot::create([
            'lot_number' => $lotNumber, 'name' => 'Lot '.$lotNumber, 'grape_variety' => $variety,
            'vintage' => '2026', 'wine_type' => 'RED', 'initial_volume' => $volume, 'current_volume' => $volume,
        ]);
        $lot->grapes()->create(['grape_variety' => $variety, 'percentage' => 100]);
        VesselLot::create(['vessel_id' => $vessel->getKey(), 'wine_lot_id' => $lot->getKey(), 'volume' => $volume]);
        $vessel->forceFill(['current_volume' => $volume, 'status' => 'IN_USE'])->save();

        return $lot;
    }

    public function test_blend_mints_a_new_lot_with_combined_composition(): void
    {
        $tenant = $this->createTenant();
        $admin = $this->createMember($tenant, [TenantRole::Admin]);
        $this->actingAsTenant($tenant);
        $vA = Vessel::create(['name' => 'A', 'type' => 'TANK', 'capacity_liters' => '1000.000']);
        $vB = Vessel::create(['name' => 'B', 'type' => 'TANK', 'capacity_liters' => '1000.000']);
        $vDest = Vessel::create(['name' => 'Dest', 'type' => 'TANK', 'capacity_liters' => '1000.000']);
        $lotA = $this->lotInVessel('LOT-2026-201', '300.000', $vA, 'Merlot');
        $lotB = $this->lotInVessel('LOT-2026-202', '200.000', $vB, 'Syrah');
        $vlA = $lotA->vesselLots()->firstOrFail();
        $vlB = $lotB->vesselLots()->firstOrFail();
        $this->forgetTenant();

        Sanctum::actingAs($admin);

        $this->postJson('/api/v1/wine-lots/blend', [
            'name' => 'Cuvée 2026',
            'vintage' => '2026',
            'wine_type' => 'RED',
            'destination_vessel_id' => $vDest->getKey(),
            'sources' => [
                ['vessel_lot_id' => $vlA->getKey(), 'volume' => 100],
                ['vessel_lot_id' => $vlB->getKey(), 'volume' => 100],
            ],
        ], $this->tenantHeader($tenant))
            ->assertCreated()
            ->assertJsonPath('data.status', 'BLENDED')
            ->assertJsonPath('data.current_volume', '200.000');

        // Sources were drained; destination holds the blend.
        $this->assertSame('200.000', (string) $lotA->refresh()->current_volume);
        $this->assertSame('100.000', (string) $lotB->refresh()->current_volume);
        $this->assertSame('200.000', (string) $vDest->refresh()->current_volume);
    }

    public function test_bulk_additions_apply_to_many_lots_and_deduct_stock(): void
    {
        $tenant = $this->createTenant();
        $admin = $this->createMember($tenant, [TenantRole::Admin]);
        $this->actingAsTenant($tenant);
        $v = Vessel::create(['name' => 'T', 'type' => 'TANK', 'capacity_liters' => '2000.000']);
        $lot1 = $this->lotInVessel('LOT-2026-210', '500.000', $v);
        $v2 = Vessel::create(['name' => 'T2', 'type' => 'TANK', 'capacity_liters' => '2000.000']);
        $lot2 = $this->lotInVessel('LOT-2026-211', '500.000', $v2);
        $product = EnologicalProduct::create(['name' => 'SO2', 'category' => 'SO2', 'unit' => 'g', 'current_stock' => '1000.000']);
        $this->forgetTenant();

        Sanctum::actingAs($admin);

        $this->postJson('/api/v1/wine-lots/additions/bulk', [
            'name' => 'SO2', 'unit' => 'g', 'enological_product_id' => $product->getKey(),
            'lots' => [
                ['wine_lot_id' => $lot1->getKey(), 'quantity' => 30],
                ['wine_lot_id' => $lot2->getKey(), 'quantity' => 30],
            ],
        ], $this->tenantHeader($tenant))
            ->assertCreated()
            ->assertJsonPath('data.created', 2);

        $this->assertSame('940.000', (string) $product->refresh()->current_stock);
    }

    public function test_costs_and_analytics_endpoints_return_data(): void
    {
        $tenant = $this->createTenant();
        $admin = $this->createMember($tenant, [TenantRole::Admin]);
        $this->actingAsTenant($tenant);
        $v = Vessel::create(['name' => 'T', 'type' => 'TANK', 'capacity_liters' => '1000.000']);
        $this->lotInVessel('LOT-2026-220', '500.000', $v);
        $this->forgetTenant();

        Sanctum::actingAs($admin);

        $this->getJson('/api/v1/cellar/costs', $this->tenantHeader($tenant))
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->getJson('/api/v1/cellar/analytics', $this->tenantHeader($tenant))
            ->assertOk()
            ->assertJsonPath('data.by_vintage.0.label', '2026');
    }

    public function test_tasting_report_create_and_list(): void
    {
        $tenant = $this->createTenant();
        $admin = $this->createMember($tenant, [TenantRole::Admin]);
        Sanctum::actingAs($admin);

        $this->postJson('/api/v1/tasting-reports', ['title' => 'June panel'], $this->tenantHeader($tenant))
            ->assertCreated()
            ->assertJsonPath('data.title', 'June panel');

        $this->getJson('/api/v1/tasting-reports', $this->tenantHeader($tenant))
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_wine_lots_list_includes_latest_analysis_and_addition(): void
    {
        $tenant = $this->createTenant();
        $admin = $this->createMember($tenant, [TenantRole::Admin]);
        $this->actingAsTenant($tenant);
        $v = Vessel::create(['name' => 'T', 'type' => 'TANK', 'capacity_liters' => '1000.000']);
        $lot = $this->lotInVessel('LOT-2026-230', '500.000', $v);
        $lot->analyses()->create(['created_by_id' => $admin->getKey(), 'date' => now(), 'free_so2' => '28.00']);
        $lot->additions()->create(['created_by_id' => $admin->getKey(), 'name' => 'SO2', 'quantity' => '20.000', 'unit' => 'g']);
        $this->forgetTenant();

        Sanctum::actingAs($admin);

        $this->getJson('/api/v1/wine-lots', $this->tenantHeader($tenant))
            ->assertOk()
            ->assertJsonPath('data.0.latest_analysis.free_so2', '28.00')
            ->assertJsonPath('data.0.latest_addition.name', 'SO2');
    }
}
