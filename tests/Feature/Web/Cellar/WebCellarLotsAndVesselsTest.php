<?php

declare(strict_types=1);

namespace Tests\Feature\Web\Cellar;

use App\Enums\TenantRole;
use App\Models\AuditLog;
use App\Models\CellarAddition;
use App\Models\CellarAnalysis;
use App\Models\CellarProcess;
use App\Models\CellarTastingNote;
use App\Models\EnologicalProduct;
use App\Models\FermentationTemplate;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Vessel;
use App\Models\VesselLot;
use App\Models\WineLot;
use App\Models\WorkOrder;
use App\Services\Auth\ActiveTenantSession;
use App\Services\Cellar\VesselVolumeSync;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;
use Symfony\Component\HttpFoundation\Response;
use Tests\Concerns\InteractsWithTenancy;
use Tests\TestCase;

/** Cellar home + map, lots list, lot page and vessel page as Inertia pages (parity areas A–D, G, H, L, P1). */
class WebCellarLotsAndVesselsTest extends TestCase
{
    use InteractsWithTenancy;
    use RefreshDatabase;

    private Tenant $tenant;

    private User $cellar;

    private User $admin;

    private WineLot $lot;

    private Vessel $t1;

    private Vessel $t2;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-14 09:00:00');
        $this->tenant = $this->createTenant();
        $this->cellar = $this->createMember($this->tenant, [TenantRole::Cellar]);
        $this->admin = $this->createMember($this->tenant, [TenantRole::Admin]);

        $this->actingAsTenant($this->tenant);
        $this->t1 = Vessel::create(['name' => 'T1', 'type' => 'TANK', 'capacity_liters' => '1000.000', 'room' => 'Hall', 'position_x' => 10, 'position_y' => 10]);
        $this->t2 = Vessel::create(['name' => 'T2', 'type' => 'TANK', 'capacity_liters' => '1000.000', 'room' => 'Hall']);
        $this->lot = WineLot::create(['lot_number' => 'LOT-2026-001', 'name' => 'Plavac', 'grape_variety' => 'Plavac', 'vintage' => '2026',
            'wine_type' => 'RED', 'status' => 'AGING', 'initial_volume' => '600.000', 'current_volume' => '600.000', 'grape_price_per_kg' => 200, 'grape_cost' => 160000, 'harvest_weight_kg' => '800.000']);
        VesselLot::create(['vessel_id' => $this->t1->getKey(), 'wine_lot_id' => $this->lot->getKey(), 'volume' => '500.000']);
        app(VesselVolumeSync::class)->syncMany([(string) $this->t1->getKey()]);
        $this->forgetTenant();
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
     * @param  array<string, mixed>  $data
     * @param  array<string, string>  $headers
     * @return TestResponse<Response>
     */
    private function web(string $method, string $uri, array $data = [], ?User $as = null, array $headers = []): TestResponse
    {
        return $this->actingAs($as ?? $this->cellar)
            ->withSession([ActiveTenantSession::KEY => $this->tenant->getKey()])
            ->from('/cellar')
            ->call($method, $uri, $data, [], [], $this->transformHeadersToServerVars($headers));
    }

    public function test_home_shows_figures_map_lots_and_archive(): void
    {
        $this->inTenant(function (): void {
            CellarAnalysis::create(['wine_lot_id' => $this->lot->getKey(), 'vessel_id' => $this->t1->getKey(), 'date' => now()->subDay(), 'free_so2' => 20, 'total_so2' => 70, 'created_by_id' => $this->cellar->getKey()]);
            $so2 = EnologicalProduct::create(['name' => 'K2S2O5', 'category' => 'SO2', 'unit' => 'g', 'current_stock' => '100.000', 'so2_uplift_per_unit' => '570']);
            CellarAddition::create(['wine_lot_id' => $this->lot->getKey(), 'vessel_id' => $this->t1->getKey(), 'enological_product_id' => $so2->getKey(), 'created_by_id' => $this->cellar->getKey(), 'name' => 'K2S2O5', 'quantity' => '8.772', 'unit' => 'g']);
            WineLot::create(['lot_number' => 'LOT-2024-001', 'name' => 'Old', 'grape_variety' => 'Pošip', 'vintage' => '2024', 'status' => 'BOTTLED', 'initial_volume' => '100.000', 'current_volume' => '0.000']);
        });

        $this->web('GET', '/cellar')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Cellar/Index')
            ->where('stats.vessels', 2)
            ->where('stats.lots', 1)
            ->where('stats.volume', '600.000')
            ->has('vessels', 2)
            ->where('vessels.0.lots.0.so2.free', 20)
            ->where('vessels.0.lots.0.so2.in_vessel', true)
            ->where('vessels.0.lots.0.so2.projected', 30)          // 8.772 g × 570 ÷ 500 L ≈ +10
            ->has('lots', 1)
            ->where('bottled.0.name', 'Old')
            ->where('bottled.0.counts.bottlings', 0)
            ->where('lotOptions.0.unallocated', '100.000'));
    }

    public function test_map_edits_layout_duplicates_bulk_adds_and_renames(): void
    {
        $this->web('PATCH', '/cellar/vessels/layout', ['updates' => [['id' => $this->t2->getKey(), 'position_x' => 120, 'position_y' => 10, 'map_width' => 90, 'map_height' => 90]]], headers: ['Accept' => 'application/json'])
            ->assertNoContent();
        $this->web('POST', '/cellar/vessels/duplicate', ['vessel_ids' => [$this->t2->getKey()], 'count' => 2])->assertSessionHas('success');
        $this->web('POST', '/cellar/vessels/bulk', ['prefix' => 'B', 'start_number' => 1, 'count' => 3, 'type' => 'BARRIQUE', 'capacity_liters' => 225, 'room' => 'Barrels'])->assertSessionHas('success');
        $this->web('POST', '/cellar/rooms/rename', ['from' => 'Hall', 'to' => 'Big hall'])->assertSessionHas('success');
        $this->web('PATCH', "/cellar/vessels/{$this->t2->getKey()}", ['is_faulty' => true, 'fault_note' => 'Valve'])->assertSessionHas('success');

        $this->inTenant(function (): void {
            $this->assertSame(120, $this->t2->refresh()->position_x);
            $this->assertTrue($this->t2->is_faulty);
            $this->assertSame(['T3', 'T4'], Vessel::query()->whereIn('name', ['T3', 'T4'])->orderBy('name')->pluck('name')->all());
            $this->assertSame(3, Vessel::query()->where('type', 'BARRIQUE')->count());
            $this->assertSame(4, Vessel::query()->where('room', 'Big hall')->count());
        });
    }

    public function test_assign_wine_previews_then_places_it(): void
    {
        $this->web('POST', "/cellar/lots/{$this->lot->getKey()}/vessels/bulk", ['vessel_ids' => [$this->t2->getKey()], 'source' => 'existing', 'mode' => 'fill', 'dry_run' => true], headers: ['Accept' => 'application/json'])
            ->assertOk()->assertJsonPath('data.lines.0.volume', '100.000');
        $this->inTenant(fn () => $this->assertSame('0.000', (string) $this->t2->refresh()->current_volume, 'dry run changes nothing'));

        $this->web('POST', "/cellar/lots/{$this->lot->getKey()}/vessels/bulk", ['vessel_ids' => [$this->t2->getKey()], 'source' => 'existing', 'mode' => 'fill'])->assertSessionHas('success');
        $this->inTenant(fn () => $this->assertSame('100.000', (string) $this->t2->refresh()->current_volume));

        $vl = $this->inTenant(fn () => VesselLot::query()->where('vessel_id', $this->t2->getKey())->sole());
        $this->web('DELETE', "/cellar/lots/{$this->lot->getKey()}/vessels/{$vl->getKey()}")->assertSessionHas('success');
        $this->inTenant(fn () => $this->assertSame('0.000', (string) $this->t2->refresh()->current_volume));
    }

    public function test_lot_page_and_money_only_for_finance(): void
    {
        $this->web('GET', "/cellar/lots/{$this->lot->getKey()}")->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Cellar/Lots/Show')
            ->where('lot.name', 'Plavac')
            ->where('lot.vessels.0.vessel_name', 'T1')
            ->where('lot.cost_breakdown', null)
            ->where('lot.grape_price_per_kg', null)
            ->has('timeline')
            ->has('vessels', 2)
            ->missing('history'));

        $this->web('GET', "/cellar/lots/{$this->lot->getKey()}", as: $this->admin)->assertInertia(fn (AssertableInertia $page) => $page
            ->where('lot.grape_price_per_kg.minor', 200)
            ->where('lot.cost_breakdown.grape', 160000));                 // 800 kg × 2.00

        $this->web('GET', "/cellar/lots/{$this->lot->getKey()}", headers: $this->inertiaPartial('Cellar/Lots/Show', ['history', 'trace']))->assertOk()->assertJsonPath('props.trace.name', 'Plavac')->assertJsonStructure(['props' => ['history']]);
    }

    public function test_lot_lists_are_newest_first_whatever_order_they_were_recorded_in(): void
    {
        // Imported history arrives in any order; "Latest analysis" is element 0 and must be the newest reading.
        $this->inTenant(function (): void {
            foreach (['2026-09-17' => 42, '2026-08-17' => 29, '2026-09-11' => 22] as $date => $free) {
                CellarAnalysis::create(['wine_lot_id' => $this->lot->getKey(), 'date' => $date, 'free_so2' => $free, 'created_by_id' => $this->cellar->getKey()]);
            }
        });

        $this->web('GET', "/cellar/lots/{$this->lot->getKey()}")->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->where('lot.analyses.0.free_so2', '42.00')
            ->where('lot.analyses.1.free_so2', '22.00')
            ->where('lot.analyses.2.free_so2', '29.00'));
    }

    public function test_every_lot_write_from_the_lot_page(): void
    {
        $id = $this->lot->getKey();
        [$product, $template] = $this->inTenant(fn () => [
            EnologicalProduct::create(['name' => 'Bentonite', 'category' => 'FINING', 'unit' => 'g', 'current_stock' => '500.000']),
            FermentationTemplate::create(['name' => 'Red']),
        ]);

        $this->web('POST', "/cellar/lots/{$id}/analyses", ['vessel_id' => $this->t1->getKey(), 'free_so2' => 22, 'ph' => 3.5])->assertSessionHas('success');
        $analysis = $this->inTenant(fn () => CellarAnalysis::query()->sole());
        $this->web('PATCH', "/cellar/lots/{$id}/analyses/{$analysis->getKey()}", ['free_so2' => 24])->assertSessionHas('success');
        $this->web('POST', "/cellar/lots/{$id}/additions", ['name' => 'Bentonite', 'quantity' => 50, 'unit' => 'g', 'enological_product_id' => $product->getKey(), 'vessel_id' => $this->t1->getKey()])->assertSessionHas('success');
        $this->web('POST', "/cellar/lots/{$id}/processes", ['kind' => 'Pump-over', 'vessel_id' => $this->t1->getKey()])->assertSessionHas('success');
        $this->web('POST', "/cellar/lots/{$id}/tasting-notes", ['score' => 88, 'nose' => 'Cherry', 'vessel_id' => $this->t1->getKey()])->assertSessionHas('success');
        $this->web('POST', "/cellar/lots/{$id}/vessels", ['vessel_id' => $this->t2->getKey(), 'volume' => 50])->assertSessionHas('success');
        $this->web('POST', "/cellar/lots/{$id}/transfers", ['type' => 'RACK', 'to_lot_id' => $id, 'from_vessel_id' => $this->t1->getKey(), 'to_vessel_id' => $this->t2->getKey(), 'volume_liters' => 100])->assertSessionHas('success');
        $this->web('POST', "/cellar/lots/{$id}/adjust-volume", ['delta' => -10, 'reason' => 'Sample'])->assertSessionHas('success');
        $this->web('POST', "/cellar/lots/{$id}/protocol", ['fermentation_template_id' => $template->getKey()])->assertSessionHas('success');
        $this->web('POST', "/cellar/lots/{$id}/bottlings", ['bottle_count' => 100])->assertSessionHas('success');
        $this->web('PATCH', "/cellar/lots/{$id}", ['notes' => 'Lovely'])->assertSessionHas('success');

        $this->inTenant(function () use ($product, $analysis, $id): void {
            $this->assertSame('24.00', number_format((float) $analysis->refresh()->free_so2, 2));
            $this->assertSame('450.000', (string) $product->refresh()->current_stock);
            $this->assertSame(1, CellarProcess::query()->where('kind', 'Pump-over')->count());
            $this->assertSame(1, CellarTastingNote::query()->count());
            $this->assertSame('Lovely', WineLot::query()->whereKey($id)->firstOrFail()->notes);
            $this->assertSame('515.000', (string) WineLot::query()->whereKey($id)->firstOrFail()->current_volume); // 600 − 10 − 75 bottled
            $this->assertTrue(AuditLog::query()->where('action', 'wine_lot.volume_adjusted')->exists());
        });

        $this->web('DELETE', "/cellar/lots/{$id}/analyses/{$analysis->getKey()}")->assertSessionHas('success');

        // The journey mixes every record type — tasting notes and transfers included.
        $this->web('GET', "/cellar/lots/{$id}")->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->where('timeline', function (mixed $events): bool {
                $types = [];
                foreach ($events instanceof Collection ? $events->all() : (array) $events as $e) {
                    $types[] = $e instanceof Collection ? $e->get('type') : ((array) $e)['type'];
                }

                return array_diff(['tasting', 'transfer', 'bottling', 'process'], $types) === [];
            }));
    }

    public function test_create_lot_redirects_to_it_and_only_admins_delete(): void
    {
        $response = $this->web('POST', '/cellar/lots', [
            'name' => 'Pošip', 'grape_variety' => 'Pošip', 'vintage' => '2026', 'initial_volume' => 300, 'vessel_id' => $this->t2->getKey(),
            'grapes' => [['grape_variety' => 'Pošip', 'percentage' => 100]],
        ]);
        $new = $this->inTenant(fn () => WineLot::query()->where('name', 'Pošip')->sole());
        $response->assertRedirect('/cellar/lots/'.$new->getKey());

        $this->web('DELETE', '/cellar/lots/'.$new->getKey())->assertForbidden();
        $this->web('DELETE', '/cellar/lots/'.$new->getKey(), as: $this->admin)->assertRedirect('/cellar');
        $this->web('DELETE', '/cellar/vessels/'.$this->t1->getKey(), as: $this->admin)->assertSessionHasErrors();
    }

    public function test_lots_list_filters_and_vessel_page(): void
    {
        $this->web('GET', '/cellar/lots?vintage=2026&status=AGING')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Cellar/Lots/Index')->has('lots', 1)->where('filters.vintage', '2026'));
        $this->web('GET', '/cellar/lots?vintage=2020')->assertInertia(fn (AssertableInertia $page) => $page->has('lots', 0));

        $this->web('GET', '/cellar/vessels/'.$this->t1->getKey())->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Cellar/Vessels/Show')
            ->where('vessel.name', 'T1')
            ->where('vessel.lots.0.lot.name', 'Plavac')
            ->has('vessel.so2', 1)
            ->where('rooms', ['Hall']));
    }

    public function test_work_order_can_name_its_wine_lot(): void
    {
        $this->web('GET', '/work-orders', headers: $this->inertiaPartial('WorkOrders/Index', 'wineLotOptions'))->assertOk()->assertJsonPath('props.wineLotOptions.0.label', 'Plavac 2026');

        $this->web('POST', '/work-orders', ['title' => 'Rack T1', 'wine_lot_id' => $this->lot->getKey()])->assertSessionHas('success');
        $this->inTenant(fn () => $this->assertSame($this->lot->getKey(), WorkOrder::query()->where('title', 'Rack T1')->value('wine_lot_id')));
    }
}
