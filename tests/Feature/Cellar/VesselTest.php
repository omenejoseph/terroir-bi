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

class VesselTest extends TestCase
{
    use InteractsWithTenancy;
    use RefreshDatabase;

    public function test_create_and_list_vessels_for_the_map(): void
    {
        $tenant = $this->createTenant();
        $admin = $this->createMember($tenant, [TenantRole::Admin]);
        Sanctum::actingAs($admin);

        $this->postJson('/api/v1/vessels', [
            'name' => 'Tank A1',
            'type' => 'TANK',
            'capacity_liters' => 1000,
            'room' => 'Main Cellar',
        ], $this->tenantHeader($tenant))
            ->assertCreated()
            ->assertJsonPath('data.name', 'Tank A1')
            ->assertJsonPath('data.status', 'AVAILABLE')
            ->assertJsonPath('data.current_volume', '0.000');

        $this->getJson('/api/v1/vessels', $this->tenantHeader($tenant))
            ->assertOk()
            ->assertJsonPath('data.0.name', 'Tank A1')
            ->assertJsonCount(1, 'data');
    }

    public function test_layout_update_persists_positions(): void
    {
        $tenant = $this->createTenant();
        $admin = $this->createMember($tenant, [TenantRole::Admin]);
        $this->actingAsTenant($tenant);
        $vessel = Vessel::create(['name' => 'T1', 'type' => 'TANK', 'capacity_liters' => '500.000']);
        $this->forgetTenant();

        Sanctum::actingAs($admin);

        $this->patchJson('/api/v1/vessels/layout', [
            'updates' => [
                ['id' => $vessel->getKey(), 'position_x' => 120, 'position_y' => 80, 'room' => 'Barrel Room'],
            ],
        ], $this->tenantHeader($tenant))->assertNoContent();

        $this->assertDatabaseHas('vessels', [
            'id' => $vessel->getKey(),
            'position_x' => 120,
            'position_y' => 80,
            'room' => 'Barrel Room',
        ]);
    }

    public function test_cannot_delete_a_vessel_that_holds_wine(): void
    {
        $tenant = $this->createTenant();
        $admin = $this->createMember($tenant, [TenantRole::Admin]);
        $this->actingAsTenant($tenant);
        $vessel = Vessel::create(['name' => 'T1', 'type' => 'TANK', 'capacity_liters' => '500.000']);
        $lot = WineLot::create([
            'lot_number' => 'LOT-2026-001', 'name' => 'Cuvée', 'grape_variety' => 'Plavac',
            'vintage' => '2026', 'initial_volume' => '300.000', 'current_volume' => '300.000',
        ]);
        VesselLot::create([
            'vessel_id' => $vessel->getKey(), 'wine_lot_id' => $lot->getKey(), 'volume' => '300.000',
        ]);
        $this->forgetTenant();

        Sanctum::actingAs($admin);

        $this->deleteJson("/api/v1/vessels/{$vessel->getKey()}", [], $this->tenantHeader($tenant))
            ->assertStatus(422);

        $this->assertDatabaseHas('vessels', ['id' => $vessel->getKey()]);
    }
}
