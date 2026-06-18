<?php

declare(strict_types=1);

namespace Tests\Feature\Cellar;

use App\Enums\TenantRole;
use App\Models\Vessel;
use App\Models\VesselLot;
use App\Models\WineLot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\InteractsWithTenancy;
use Tests\TestCase;

class WineLotTest extends TestCase
{
    use InteractsWithTenancy;
    use RefreshDatabase;

    public function test_create_lot_assigns_number_and_fills_starting_vessel(): void
    {
        $tenant = $this->createTenant();
        $admin = $this->createMember($tenant, [TenantRole::Admin]);
        $this->actingAsTenant($tenant);
        $vessel = Vessel::create(['name' => 'T1', 'type' => 'TANK', 'capacity_liters' => '1000.000']);
        $this->forgetTenant();

        Sanctum::actingAs($admin);

        $this->postJson('/api/v1/wine-lots', [
            'name' => 'Plavac 2026',
            'grape_variety' => 'Plavac Mali',
            'vintage' => '2026',
            'wine_type' => 'RED',
            'initial_volume' => 600,
            'grape_price_per_kg' => 150, // €1.50/kg in minor units
            'harvest_weight_kg' => 900,
            'vessel_id' => $vessel->getKey(),
        ], $this->tenantHeader($tenant))
            ->assertCreated()
            ->assertJsonPath('data.lot_number', 'LOT-'.date('Y').'-001')
            ->assertJsonPath('data.current_volume', '600.000')
            ->assertJsonPath('data.grape_cost.minor', 135000) // 150 * 900
            ->assertJsonPath('data.vessels.0.volume', '600.000');

        // Vessel volume is derived from its vessel_lots.
        $this->assertSame('600.000', (string) $vessel->refresh()->current_volume);
        $this->assertSame('IN_USE', $vessel->status->value);
    }

    public function test_lot_numbers_increment_per_tenant(): void
    {
        $tenant = $this->createTenant();
        $admin = $this->createMember($tenant, [TenantRole::Admin]);
        Sanctum::actingAs($admin);

        foreach (['001', '002', '003'] as $seq) {
            $this->postJson('/api/v1/wine-lots', [
                'name' => 'Lot', 'grape_variety' => 'Babić', 'vintage' => '2026', 'initial_volume' => 100,
            ], $this->tenantHeader($tenant))
                ->assertCreated()
                ->assertJsonPath('data.lot_number', 'LOT-'.date('Y').'-'.$seq);
        }
    }

    public function test_assign_to_vessel_is_capacity_guarded(): void
    {
        $tenant = $this->createTenant();
        $admin = $this->createMember($tenant, [TenantRole::Admin]);
        $this->actingAsTenant($tenant);
        $vessel = Vessel::create(['name' => 'Small', 'type' => 'BARREL', 'capacity_liters' => '225.000']);
        $lot = WineLot::create([
            'lot_number' => 'LOT-2026-009', 'name' => 'X', 'grape_variety' => 'Plavac',
            'vintage' => '2026', 'initial_volume' => '500.000', 'current_volume' => '500.000',
        ]);
        $this->forgetTenant();

        Sanctum::actingAs($admin);

        $this->postJson("/api/v1/wine-lots/{$lot->getKey()}/vessels", [
            'vessel_id' => $vessel->getKey(),
            'volume' => 300, // exceeds the 225 L barrel
        ], $this->tenantHeader($tenant))->assertStatus(422);

        $this->assertSame('0.000', (string) $vessel->refresh()->current_volume);
    }

    public function test_bottling_status_frees_vessels(): void
    {
        $tenant = $this->createTenant();
        $admin = $this->createMember($tenant, [TenantRole::Admin]);
        $this->actingAsTenant($tenant);
        $vessel = Vessel::create(['name' => 'T1', 'type' => 'TANK', 'capacity_liters' => '1000.000']);
        $lot = WineLot::create([
            'lot_number' => 'LOT-2026-010', 'name' => 'Y', 'grape_variety' => 'Plavac',
            'vintage' => '2026', 'initial_volume' => '400.000', 'current_volume' => '400.000',
        ]);
        VesselLot::create([
            'vessel_id' => $vessel->getKey(), 'wine_lot_id' => $lot->getKey(), 'volume' => '400.000',
        ]);
        $vessel->forceFill(['current_volume' => '400.000', 'status' => 'IN_USE'])->save();
        $this->forgetTenant();

        Sanctum::actingAs($admin);

        $this->patchJson("/api/v1/wine-lots/{$lot->getKey()}", [
            'status' => 'BOTTLED',
        ], $this->tenantHeader($tenant))
            ->assertOk()
            ->assertJsonPath('data.status', 'BOTTLED')
            ->assertJsonPath('data.current_volume', '0.000');

        $this->assertSame('0.000', (string) $vessel->refresh()->current_volume);
        $this->assertSame('AVAILABLE', $vessel->status->value);
        $this->assertDatabaseCount('vessel_lots', 0);
    }
}
