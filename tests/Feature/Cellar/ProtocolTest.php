<?php

declare(strict_types=1);

namespace Tests\Feature\Cellar;

use App\Enums\TenantRole;
use App\Models\FermentationTemplate;
use App\Models\WineLot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\InteractsWithTenancy;
use Tests\TestCase;

class ProtocolTest extends TestCase
{
    use InteractsWithTenancy;
    use RefreshDatabase;

    public function test_assign_protocol_then_generate_work_orders_idempotently(): void
    {
        $tenant = $this->createTenant();
        $admin = $this->createMember($tenant, [TenantRole::Admin]);
        $this->actingAsTenant($tenant);
        $template = FermentationTemplate::create([
            'name' => 'Classic Red',
            'stages' => [[
                'name' => 'Cold Soak',
                'dayStart' => 0,
                'dayEnd' => 30,
                'actions' => [
                    ['type' => 'PUMP_OVER', 'description' => 'Daily pump-over'],
                    ['type' => 'ADDITION', 'description' => 'Add SO2'],
                ],
            ]],
        ]);
        $lot = WineLot::create([
            'lot_number' => 'LOT-2026-100', 'name' => 'Cuvée', 'grape_variety' => 'Plavac',
            'vintage' => '2026', 'initial_volume' => '500.000', 'current_volume' => '500.000',
        ]);
        $this->forgetTenant();

        Sanctum::actingAs($admin);

        $this->postJson("/api/v1/wine-lots/{$lot->getKey()}/protocol", [
            'fermentation_template_id' => $template->getKey(),
        ], $this->tenantHeader($tenant))
            ->assertOk()
            ->assertJsonPath('data.fermentation_template_id', $template->getKey());

        $this->postJson("/api/v1/wine-lots/{$lot->getKey()}/protocol/generate", [], $this->tenantHeader($tenant))
            ->assertOk()
            ->assertJsonPath('data.created', 2)
            ->assertJsonPath('data.day', 0);

        $this->assertDatabaseCount('work_orders', 2);

        // Re-running the same day creates nothing new.
        $this->postJson("/api/v1/wine-lots/{$lot->getKey()}/protocol/generate", [], $this->tenantHeader($tenant))
            ->assertOk()
            ->assertJsonPath('data.created', 0)
            ->assertJsonPath('data.skipped', 2);

        $this->assertDatabaseCount('work_orders', 2);
    }

    public function test_generate_without_a_protocol_fails(): void
    {
        $tenant = $this->createTenant();
        $admin = $this->createMember($tenant, [TenantRole::Admin]);
        $this->actingAsTenant($tenant);
        $lot = WineLot::create([
            'lot_number' => 'LOT-2026-101', 'name' => 'X', 'grape_variety' => 'Babić',
            'vintage' => '2026', 'initial_volume' => '100.000', 'current_volume' => '100.000',
        ]);
        $this->forgetTenant();

        Sanctum::actingAs($admin);

        $this->postJson("/api/v1/wine-lots/{$lot->getKey()}/protocol/generate", [], $this->tenantHeader($tenant))
            ->assertStatus(422);
    }

    public function test_bulk_create_vessels_names_sequentially(): void
    {
        $tenant = $this->createTenant();
        $admin = $this->createMember($tenant, [TenantRole::Admin]);
        Sanctum::actingAs($admin);

        $this->postJson('/api/v1/vessels/bulk', [
            'prefix' => 'F', 'start_number' => 1, 'count' => 3, 'type' => 'BARRIQUE', 'capacity_liters' => 225,
        ], $this->tenantHeader($tenant))
            ->assertCreated()
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('data.0.name', 'F1')
            ->assertJsonPath('data.2.name', 'F3');

        $this->assertDatabaseCount('vessels', 3);
    }
}
