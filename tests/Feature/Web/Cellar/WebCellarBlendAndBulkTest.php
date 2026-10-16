<?php

declare(strict_types=1);

namespace Tests\Feature\Web\Cellar;

use App\Enums\TenantRole;
use App\Models\AuditLog;
use App\Models\BlendTrial;
use App\Models\CellarAddition;
use App\Models\CellarAnalysis;
use App\Models\EnologicalProduct;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Vessel;
use App\Models\VesselLot;
use App\Models\WineLot;
use App\Services\Auth\ActiveTenantSession;
use App\Services\Cellar\VesselVolumeSync;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;
use Symfony\Component\HttpFoundation\Response;
use Tests\Concerns\InteractsWithTenancy;
use Tests\TestCase;

/** Cellar › Blend, Bulk additions and Bulk analyses as Inertia pages (parity areas I, F8–F9, G7). */
class WebCellarBlendAndBulkTest extends TestCase
{
    use InteractsWithTenancy;
    use RefreshDatabase;

    private Tenant $tenant;

    private User $cellar;

    private WineLot $a;

    private WineLot $b;

    private Vessel $ta;

    private Vessel $tb;

    private Vessel $empty;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = $this->createTenant();
        $this->cellar = $this->createMember($this->tenant, [TenantRole::Cellar]);

        $this->actingAsTenant($this->tenant);
        $this->ta = Vessel::create(['name' => 'TA', 'type' => 'TANK', 'capacity_liters' => '1000.000']);
        $this->tb = Vessel::create(['name' => 'TB', 'type' => 'TANK', 'capacity_liters' => '1000.000']);
        $this->empty = Vessel::create(['name' => 'TC', 'type' => 'TANK', 'capacity_liters' => '2000.000']);
        $this->a = $this->lot('LOT-2026-001', 'Plavac', $this->ta, '600.000', 3.4);
        $this->b = $this->lot('LOT-2026-002', 'Syrah', $this->tb, '400.000', 3.8);
        $this->forgetTenant();
    }

    private function lot(string $number, string $name, Vessel $v, string $volume, float $ph): WineLot
    {
        $lot = WineLot::create(['lot_number' => $number, 'name' => $name, 'grape_variety' => $name, 'vintage' => '2026', 'wine_type' => 'RED', 'status' => 'AGING', 'initial_volume' => $volume, 'current_volume' => $volume]);
        VesselLot::create(['vessel_id' => $v->getKey(), 'wine_lot_id' => $lot->getKey(), 'volume' => $volume]);
        app(VesselVolumeSync::class)->syncMany([(string) $v->getKey()]);
        CellarAnalysis::create(['wine_lot_id' => $lot->getKey(), 'date' => now(), 'ph' => $ph, 'free_so2' => 25, 'created_by_id' => $this->cellar->getKey()]);

        return $lot;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return TestResponse<Response>
     */
    private function web(string $method, string $uri, array $data = []): TestResponse
    {
        return $this->actingAs($this->cellar)
            ->withSession([ActiveTenantSession::KEY => $this->tenant->getKey()])
            ->from('/cellar/blend')
            ->call($method, $uri, $data);
    }

    public function test_blend_page_projection_and_blend_by_vessels(): void
    {
        $this->web('GET', '/cellar/blend')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Cellar/Blend')->has('vessels', 3)->has('lots', 2));

        $this->actingAs($this->cellar)->withSession([ActiveTenantSession::KEY => $this->tenant->getKey()])
            ->postJson('/cellar/blend/project', [
                'components' => [['wine_lot_id' => $this->a->getKey(), 'ratio' => 50], ['wine_lot_id' => $this->b->getKey(), 'ratio' => 50]],
                'target_volume' => 200,
            ])
            ->assertOk()
            ->assertJsonPath('data.components.0.volume', '100.000')
            ->assertJsonPath('data.feasible', true)
            ->assertJsonPath('data.analysis.ph.value', 3.56)      // via [H⁺]: −log10((10^−3.4 + 10^−3.8)/2) = 3.5555
            ->assertJsonPath('data.ranges.ph.optimal', [3.1, 3.6]);

        $sources = $this->inSources();
        $response = $this->web('POST', '/cellar/blend', ['destination_vessel_id' => $this->empty->getKey(), 'name' => 'Cuvée', 'sources' => $sources]);
        $blend = $this->inTenant(fn () => WineLot::query()->where('name', 'Cuvée')->sole());
        $response->assertRedirect('/cellar/lots/'.$blend->getKey());
        $this->assertSame('300.000', (string) $blend->current_volume);
    }

    public function test_blend_by_ratio_drains_the_lots_into_the_vessel(): void
    {
        $this->web('POST', '/cellar/blend/by-ratio', [
            'components' => [['wine_lot_id' => $this->a->getKey(), 'ratio' => 75], ['wine_lot_id' => $this->b->getKey(), 'ratio' => 25]],
            'target_volume' => 400,
            'destination_vessel_id' => $this->empty->getKey(),
        ])->assertRedirect()->assertSessionHas('success');

        $this->inTenant(function (): void {
            $this->assertSame('300.000', (string) $this->a->refresh()->current_volume);
            $this->assertSame('300.000', (string) $this->b->refresh()->current_volume);
            $this->assertSame('400.000', (string) $this->empty->refresh()->current_volume);
        });
    }

    public function test_blend_trials_are_saved_listed_and_deleted_within_the_tenant(): void
    {
        $components = [['wine_lot_id' => $this->a->getKey(), 'ratio' => 70], ['wine_lot_id' => $this->b->getKey(), 'ratio' => 30]];
        $this->web('POST', '/cellar/blend/trials', ['name' => 'Trial 1', 'components' => $components, 'target_volume' => 200, 'projection' => ['analysis' => ['ph' => ['value' => 3.5]]]])
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->web('POST', '/cellar/blend/trials', ['name' => '', 'components' => [$components[0]]])->assertSessionHasErrors(['name', 'components']);

        $this->web('GET', '/cellar/blend')->assertInertia(fn (AssertableInertia $page) => $page
            ->has('trials', 1)
            ->where('trials.0.name', 'Trial 1')
            ->where('trials.0.components.0.ratio', 70)
            ->where('trials.0.projection.analysis.ph.value', 3.5));

        $trial = $this->inTenant(fn () => BlendTrial::query()->sole());
        $this->inTenant(fn () => $this->assertTrue(AuditLog::query()->where('subject_id', $trial->getKey())->exists()));

        // Another winery's member can't reach it.
        $other = $this->createTenant();
        $stranger = $this->createMember($other, [TenantRole::Admin]);
        $this->actingAs($stranger)->withSession([ActiveTenantSession::KEY => $other->getKey()])
            ->delete("/cellar/blend/trials/{$trial->getKey()}")->assertNotFound();

        $this->web('DELETE', "/cellar/blend/trials/{$trial->getKey()}")->assertRedirect();
        $this->inTenant(fn () => $this->assertSame(0, BlendTrial::query()->count()));
    }

    public function test_bulk_additions_and_bulk_analyses(): void
    {
        $this->web('GET', '/cellar/bulk-additions')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->component('Cellar/BulkAdditions')->has('lots', 2)->has('products', 0));
        $this->web('GET', '/cellar/bulk-analyses')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->component('Cellar/BulkAnalyses')->has('lots', 2));

        $product = $this->inTenant(fn () => EnologicalProduct::create(['name' => 'Tannin', 'category' => 'TANNIN', 'unit' => 'g', 'current_stock' => '100.000']));
        $this->web('POST', '/cellar/bulk-additions', [
            'name' => 'Tannin', 'unit' => 'g', 'enological_product_id' => $product->getKey(),
            'lots' => [['wine_lot_id' => $this->a->getKey(), 'quantity' => 30, 'vessel_id' => $this->ta->getKey()], ['wine_lot_id' => $this->b->getKey(), 'quantity' => 20]],
        ])->assertSessionHas('success');
        $this->web('POST', '/cellar/bulk-additions', [
            'name' => 'Tannin', 'unit' => 'g', 'enological_product_id' => $product->getKey(),
            'lots' => [['wine_lot_id' => $this->a->getKey(), 'quantity' => 500]],
        ])->assertSessionHasErrors();

        $this->web('POST', '/cellar/bulk-analyses', ['analyses' => [
            ['wine_lot_id' => $this->a->getKey(), 'vessel_id' => $this->ta->getKey(), 'free_so2' => 31, 'ph' => 3.41],
            ['wine_lot_id' => $this->b->getKey(), 'total_so2' => 90],
        ]])->assertSessionHas('success');

        $this->inTenant(function () use ($product): void {
            $this->assertSame('50.000', (string) $product->refresh()->current_stock);
            $this->assertSame(2, CellarAddition::query()->count());
            $this->assertSame(4, CellarAnalysis::query()->count());
        });
    }

    /**
     * @return list<array{vessel_lot_id: string, volume: int}>
     */
    private function inSources(): array
    {
        return $this->inTenant(fn () => [
            ['vessel_lot_id' => (string) VesselLot::query()->where('vessel_id', $this->ta->getKey())->value('id'), 'volume' => 200],
            ['vessel_lot_id' => (string) VesselLot::query()->where('vessel_id', $this->tb->getKey())->value('id'), 'volume' => 100],
        ]);
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
}
