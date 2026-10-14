<?php

declare(strict_types=1);

namespace Tests\Feature\Cellar;

use App\Enums\TenantRole;
use App\Models\AuditLog;
use App\Models\CellarAnalysis;
use App\Models\CellarTransfer;
use App\Models\Cost;
use App\Models\CostItem;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Vessel;
use App\Models\VesselLot;
use App\Models\WineLot;
use App\Services\Cellar\VesselVolumeSync;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Symfony\Component\HttpFoundation\Response;
use Tests\Concerns\InteractsWithTenancy;
use Tests\TestCase;

/** Blending (by vessels and by ratio), projections and lot cost (parity slice 1d). */
class CellarBlendAndCostTest extends TestCase
{
    use InteractsWithTenancy;
    use RefreshDatabase;

    private Tenant $tenant;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-14 09:00:00');
        $this->tenant = $this->createTenant();
        $this->admin = $this->createMember($this->tenant, [TenantRole::Admin]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /**
     * @template T
     *
     * @param  callable(): T  $fn
     * @return T
     */
    private function inTenant(callable $fn): mixed
    {
        $this->actingAsTenant($this->tenant);
        try {
            return $fn();
        } finally {
            $this->forgetTenant();
        }
    }

    /**
     * @param  array<string, mixed>  $body
     * @return TestResponse<Response>
     */
    private function api(string $method, string $uri, array $body = []): TestResponse
    {
        Sanctum::actingAs($this->admin);

        return $this->json($method, '/api/v1/'.$uri, $body, $this->tenantHeader($this->tenant));
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function lotIn(Vessel $vessel, string $number, string $volume, array $extra = []): WineLot
    {
        $lot = WineLot::create(array_merge([
            'lot_number' => $number, 'name' => 'Lot '.$number, 'grape_variety' => 'Plavac', 'vintage' => '2025',
            'wine_type' => 'RED', 'initial_volume' => $volume, 'current_volume' => $volume,
        ], $extra));
        VesselLot::create(['vessel_id' => $vessel->getKey(), 'wine_lot_id' => $lot->getKey(), 'volume' => $volume]);
        app(VesselVolumeSync::class)->syncMany([$vessel->getKey()]);

        return $lot;
    }

    private function tank(string $name, string $capacity = '1000.000'): Vessel
    {
        return Vessel::create(['name' => $name, 'type' => 'TANK', 'capacity_liters' => $capacity]);
    }

    public function test_blend_numbers_names_and_carries_cost_drawing_from_the_chosen_vessels(): void
    {
        [$a, $b, $a1, $a2, $b1, $dest] = $this->inTenant(function () {
            $a1 = $this->tank('A1');
            $a2 = $this->tank('A2');
            $b1 = $this->tank('B1');
            $dest = $this->tank('DEST');
            $a = $this->lotIn($a1, 'LOT-2025-001', '400.000', ['name' => 'Plavac', 'grape_cost' => 40000, 'vintage' => '2024']);
            VesselLot::create(['vessel_id' => $a2->getKey(), 'wine_lot_id' => $a->getKey(), 'volume' => '400.000']);
            $a->forceFill(['initial_volume' => '800.000', 'current_volume' => '800.000'])->save();
            app(VesselVolumeSync::class)->syncMany([$a2->getKey()]);
            $b = $this->lotIn($b1, 'LOT-2025-002', '500.000', ['name' => 'Syrah', 'grape_variety' => 'Syrah', 'grape_cost' => 100000]);

            return [$a, $b, $a1, $a2, $b1, $dest];
        });

        $vlA2 = $this->inTenant(fn () => VesselLot::query()->where('vessel_id', $a2->getKey())->sole());
        $vlB1 = $this->inTenant(fn () => VesselLot::query()->where('vessel_id', $b1->getKey())->sole());

        $res = $this->api('POST', 'wine-lots/blend', [
            'destination_vessel_id' => $dest->getKey(),
            'sources' => [
                ['vessel_lot_id' => $vlA2->getKey(), 'volume' => 300],
                ['vessel_lot_id' => $vlB1->getKey(), 'volume' => 100],
            ],
        ])->assertCreated()
            ->assertJsonPath('data.lot_number', 'BLEND-261014')
            ->assertJsonPath('data.name', 'Blend 2025 — Plavac + Syrah')
            ->assertJsonPath('data.vintage', '2025')
            ->assertJsonPath('data.wine_type', 'RED')
            ->assertJsonPath('data.carried_cost', 35000); // 50/L × 300 + 200/L × 100

        $this->inTenant(function () use ($a1, $a2, $res): void {
            $this->assertSame('400.000', (string) $a1->refresh()->current_volume, 'A1 untouched — drawn from the chosen vessel');
            $this->assertSame('100.000', (string) $a2->refresh()->current_volume);
            $log = AuditLog::query()->where('action', 'wine_lot.blended')->sole();
            $this->assertSame($res->json('data.id'), $log->subject_id);
        });

        // Second blend the same day gets a suffix; in-place destination allowed.
        $vlB1 = $this->inTenant(fn () => VesselLot::query()->where('vessel_id', $b1->getKey())->sole());
        $vlA1 = $this->inTenant(fn () => VesselLot::query()->where('vessel_id', $a1->getKey())->sole());
        $this->api('POST', 'wine-lots/blend', [
            'name' => 'Second',
            'destination_vessel_id' => $b1->getKey(),
            'sources' => [
                ['vessel_lot_id' => $vlA1->getKey(), 'volume' => 100],
                ['vessel_lot_id' => $vlB1->getKey(), 'volume' => 400],
            ],
        ])->assertCreated()->assertJsonPath('data.lot_number', 'BLEND-261014-1');
        unset($a, $b);
    }

    public function test_lot_cost_includes_linked_cost_lines_and_carried_cost(): void
    {
        $lot = $this->inTenant(function () {
            $lot = $this->lotIn($this->tank('T1'), 'LOT-2025-001', '1000.000', ['grape_cost' => 100000, 'carried_cost' => 20000]);
            $cost = Cost::create(['description' => 'Barrels', 'total_amount' => 30000, 'date' => now(), 'category' => 'EQUIPMENT', 'created_by_id' => $this->admin->getKey()]);
            CostItem::create(['cost_id' => $cost->getKey(), 'description' => 'Oak', 'quantity' => '1', 'unit_price' => 30000, 'total' => 30000, 'wine_lot_id' => $lot->getKey()]);

            return $lot;
        });

        $this->api('GET', "wine-lots/{$lot->getKey()}")
            ->assertOk()
            ->assertJsonPath('data.cost_breakdown.linked', 30000)
            ->assertJsonPath('data.cost_breakdown.carried', 20000)
            ->assertJsonPath('data.cost_breakdown.total', 150000)
            ->assertJsonPath('data.cost_breakdown.per_liter', 150);

        $this->api('GET', 'cellar/costs')->assertOk()->assertJsonPath('data.0.cost.linked', 30000);
    }

    public function test_projection_weights_values_averages_ph_through_hydrogen_ions_and_grades(): void
    {
        [$a, $b] = $this->inTenant(function () {
            $a = $this->lotIn($this->tank('A'), 'LOT-2025-001', '500.000', ['grape_cost' => 50000]);
            $b = $this->lotIn($this->tank('B'), 'LOT-2025-002', '500.000', ['grape_variety' => 'Syrah', 'wine_type' => 'WHITE']);
            $by = $this->admin->getKey();
            CellarAnalysis::create(['wine_lot_id' => $a->getKey(), 'created_by_id' => $by, 'date' => now(), 'ph' => 3.0, 'alcohol' => 13.0, 'volatile_acidity' => 0.5]);
            CellarAnalysis::create(['wine_lot_id' => $b->getKey(), 'created_by_id' => $by, 'date' => now(), 'ph' => 4.0, 'alcohol' => 15.0, 'volatile_acidity' => 0.9]);

            return [$a, $b];
        });

        $p = $this->api('POST', 'wine-lots/blend/project', [
            'components' => [['wine_lot_id' => $a->getKey(), 'ratio' => 50], ['wine_lot_id' => $b->getKey(), 'ratio' => 50]],
            'target_volume' => 600,
        ])->assertOk()->json('data');

        $this->assertEquals(3.26, $p['analysis']['ph']['value']);  // -log10((10^-3 + 10^-4) / 2), not 3.5
        $this->assertSame('optimal', $p['analysis']['ph']['grade']);
        $this->assertEquals(14.0, $p['analysis']['alcohol']['value']);
        $this->assertSame('watch', $p['analysis']['volatile_acidity']['grade']); // 0.7
        $this->assertNull($p['analysis']['free_so2']['value']);
        $this->assertSame('300.000', $p['components'][0]['volume']);
        $this->assertNull($p['wine_type'], 'mixed types');
        $this->assertSame(50, $p['cost_per_liter']);     // (100/L + 0) / 2
        $this->assertEquals(['Plavac' => 50.0, 'Syrah' => 50.0], $p['composition']);
        $this->assertTrue($p['feasible']);

        $this->api('POST', 'wine-lots/blend/project', [
            'components' => [['wine_lot_id' => $a->getKey(), 'ratio' => 60], ['wine_lot_id' => $b->getKey(), 'ratio' => 30]],
            'target_volume' => 600,
        ])->assertUnprocessable()->assertJsonValidationErrors(['components']);
    }

    public function test_blend_by_ratio_pulls_from_each_lots_vessels_oldest_first(): void
    {
        [$a, $b, $old, $new, $dest] = $this->inTenant(function () {
            $old = $this->tank('OLD');
            $new = $this->tank('NEW');
            $a = $this->lotIn($old, 'LOT-2025-001', '100.000');
            VesselLot::query()->where('vessel_id', $old->getKey())->update(['added_at' => now()->subMonth()]);
            VesselLot::create(['vessel_id' => $new->getKey(), 'wine_lot_id' => $a->getKey(), 'volume' => '300.000']);
            $a->forceFill(['current_volume' => '400.000', 'initial_volume' => '400.000'])->save();
            app(VesselVolumeSync::class)->syncMany([$new->getKey()]);
            $b = $this->lotIn($this->tank('B'), 'LOT-2025-002', '500.000', ['grape_variety' => 'Syrah']);

            return [$a, $b, $old, $new, $this->tank('DEST', '400.000')];
        });

        $this->api('POST', 'wine-lots/blend/by-ratio', [
            'components' => [['wine_lot_id' => $a->getKey(), 'ratio' => 75], ['wine_lot_id' => $b->getKey(), 'ratio' => 25]],
            'destination_vessel_id' => $dest->getKey(),   // 400 L of room → 300 L A + 100 L B
        ])->assertCreated()->assertJsonPath('data.current_volume', '400.000');

        $this->inTenant(function () use ($old, $new, $a): void {
            $this->assertSame('0.000', (string) $old->refresh()->current_volume, 'oldest vessel emptied first');
            $this->assertSame('100.000', (string) $new->refresh()->current_volume);
            $this->assertSame('100.000', (string) $a->refresh()->current_volume);
        });
    }

    public function test_reversing_a_blend_in_restores_volume_composition_and_cost(): void
    {
        [$lot, $mine, $source] = $this->inTenant(function () {
            $mine = $this->tank('T1');
            $source = $this->tank('T2');
            $lot = $this->lotIn($mine, 'LOT-2025-001', '600.000');
            $lot->grapes()->create(['grape_variety' => 'Plavac', 'percentage' => '100.00']);
            $this->lotIn($source, 'LOT-2025-002', '400.000', ['grape_variety' => 'Syrah', 'grape_cost' => 80000]);

            return [$lot, $mine, $source];
        });

        $this->api('POST', "wine-lots/{$lot->getKey()}/blend-in", ['from_vessel_id' => $source->getKey(), 'to_vessel_id' => $mine->getKey(), 'volume' => 200])->assertOk();
        $transferId = $this->inTenant(fn () => CellarTransfer::query()->value('id'));

        $this->api('DELETE', "wine-lots/{$lot->getKey()}/transfers/{$transferId}")->assertOk();

        $this->inTenant(function () use ($lot, $mine, $source): void {
            $lot->refresh();
            $this->assertSame('600.000', (string) $lot->current_volume);
            $this->assertSame('600.000', (string) $mine->refresh()->current_volume);
            $this->assertSame('400.000', (string) $source->refresh()->current_volume);
            $this->assertSame(['Plavac' => '100.00'], $lot->grapes()->pluck('percentage', 'grape_variety')->map(fn ($p) => (string) $p)->all());
            $this->assertSame(0, $lot->carried_cost);
            $this->assertTrue(AuditLog::query()->where('action', 'cellar_transfer.reversed')->exists());
        });
    }
}
