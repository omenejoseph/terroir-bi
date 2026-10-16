<?php

declare(strict_types=1);

namespace Tests\Feature\Web\Cellar;

use App\Enums\TenantRole;
use App\Models\AuditLog;
use App\Models\CellarAddition;
use App\Models\CellarAnalysis;
use App\Models\CellarProcess;
use App\Models\CellarTastingNote;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Vessel;
use App\Models\VesselLot;
use App\Models\WineLot;
use App\Services\Auth\ActiveTenantSession;
use App\Services\Cellar\VesselVolumeSync;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;
use Symfony\Component\HttpFoundation\Response;
use Tests\Concerns\InteractsWithTenancy;
use Tests\TestCase;

/**
 * The lot page's tabs: the active tab's table pages on the server, every tab narrows to one barrel
 * (?vessel=), and the Barrels tab shows where each barrel is plus the litres-weighted combined row.
 */
class WebCellarLotTabsTest extends TestCase
{
    use InteractsWithTenancy;
    use RefreshDatabase;

    private Tenant $tenant;

    private User $cellar;

    private WineLot $lot;

    private Vessel $b1;

    private Vessel $b2;

    private Vessel $b3;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-14 09:00:00');
        $this->tenant = $this->createTenant();
        $this->cellar = $this->createMember($this->tenant, [TenantRole::Cellar]);

        $this->actingAsTenant($this->tenant);
        $this->b1 = Vessel::create(['name' => 'B1', 'type' => 'BARREL', 'capacity_liters' => '225.000', 'room' => 'Cave', 'location' => 'Row 2']);
        $this->b2 = Vessel::create(['name' => 'B2', 'type' => 'BARREL', 'capacity_liters' => '225.000', 'room' => 'Cave']);
        $this->b3 = Vessel::create(['name' => 'B3', 'type' => 'BARREL', 'capacity_liters' => '225.000', 'room' => 'Annex']);
        $this->lot = WineLot::create(['lot_number' => 'L-1', 'name' => 'Aleph', 'grape_variety' => 'Merlot', 'vintage' => '2022', 'wine_type' => 'RED', 'status' => 'AGING', 'initial_volume' => '600.000', 'current_volume' => '600.000']);
        // 300 L in B1, 100 L in B2, 200 L in B3 — and an unrelated wine sharing B2.
        foreach ([[$this->b1, '300.000'], [$this->b2, '100.000'], [$this->b3, '200.000']] as [$vessel, $litres]) {
            VesselLot::create(['vessel_id' => $vessel->getKey(), 'wine_lot_id' => $this->lot->getKey(), 'volume' => $litres]);
        }
        $other = WineLot::create(['lot_number' => 'L-2', 'name' => 'Other wine', 'grape_variety' => 'Syrah', 'vintage' => '2023', 'wine_type' => 'RED', 'status' => 'AGING', 'initial_volume' => '50.000', 'current_volume' => '50.000']);
        VesselLot::create(['vessel_id' => $this->b2->getKey(), 'wine_lot_id' => $other->getKey(), 'volume' => '50.000']);
        app(VesselVolumeSync::class)->syncMany([(string) $this->b1->getKey(), (string) $this->b2->getKey(), (string) $this->b3->getKey()]);

        $by = $this->cellar->getKey();
        // Free SO₂: B1 20, B2 40 (read twice — newest counts), B3 none; plus a lot-level reading of 30.
        CellarAnalysis::create(['wine_lot_id' => $this->lot->getKey(), 'vessel_id' => $this->b1->getKey(), 'date' => '2026-10-01', 'free_so2' => 20, 'ph' => 3.4, 'created_by_id' => $by]);
        CellarAnalysis::create(['wine_lot_id' => $this->lot->getKey(), 'vessel_id' => $this->b2->getKey(), 'date' => '2026-09-01', 'free_so2' => 10, 'created_by_id' => $by]);
        CellarAnalysis::create(['wine_lot_id' => $this->lot->getKey(), 'vessel_id' => $this->b2->getKey(), 'date' => '2026-10-02', 'free_so2' => 40, 'created_by_id' => $by]);
        CellarAnalysis::create(['wine_lot_id' => $this->lot->getKey(), 'date' => '2026-10-03', 'free_so2' => 30, 'created_by_id' => $by]);
        $this->forgetTenant();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @return TestResponse<Response> */
    private function page(string $query = '')
    {
        return $this->actingAs($this->cellar)->withSession([ActiveTenantSession::KEY => $this->tenant->getKey()])
            ->get("/cellar/lots/{$this->lot->getKey()}{$query}");
    }

    public function test_the_barrels_tab_lists_every_barrel_with_its_location_and_the_combined_row(): void
    {
        $this->page('?tab=barrels')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->where('scope.tab', 'barrels')
            ->where('table.meta.total', 3)
            ->where('table.data.0.name', 'B1')
            ->where('table.data.0.room', 'Cave')
            ->where('table.data.0.location', 'Row 2')
            ->where('table.data.2.room', 'Annex')
            ->where('table.data.0.volume', '300.000')
            // B2 shares its barrel with another wine.
            ->where('table.data.1.others.0.name', 'Other wine')
            ->where('barrels.combined.barrels', 3)
            ->where('barrels.combined.volume', '600.000'));
    }

    public function test_the_combined_figures_are_weighted_by_litres_and_skip_barrels_without_a_reading(): void
    {
        $this->page('?tab=barrels')->assertInertia(function (AssertableInertia $page): void {
            $combined = $page->toArray()['props']['barrels']['combined']['parameters']['free_so2'];
            // B1 20 L-weighted 300, B2 newest 40 weighted 100, B3 falls back to the lot-level 30 weighted 200:
            // (20×300 + 40×100 + 30×200) / 600 = 26.67
            $this->assertEqualsWithDelta(26.67, $combined['average'], 0.01);
            $this->assertSame(3, $combined['barrels_counted']);
            $this->assertEquals(20, $combined['min']);
            $this->assertEquals(40, $combined['max']);

            // pH was only read in B1: the average is that reading, from one barrel — not diluted by zeros.
            $ph = $page->toArray()['props']['barrels']['combined']['parameters']['ph'];
            $this->assertEqualsWithDelta(3.4, $ph['average'], 0.001);
            $this->assertSame(1, $ph['barrels_counted']);
        });
    }

    public function test_a_barrels_own_reading_beats_the_lot_level_one_and_the_fallback_is_flagged(): void
    {
        $this->page('?tab=barrels')->assertInertia(function (AssertableInertia $page): void {
            $rows = $page->toArray()['props']['table']['data'];
            $this->assertEquals(20, $rows[0]['readings']['free_so2']['value']);
            $this->assertTrue($rows[0]['readings']['free_so2']['in_vessel']);
            $this->assertEquals(40, $rows[1]['readings']['free_so2']['value'], 'the newest of B2\'s own readings');
            $this->assertEquals(30, $rows[2]['readings']['free_so2']['value'], 'B3 has none of its own');
            $this->assertFalse($rows[2]['readings']['free_so2']['in_vessel']);
        });
    }

    public function test_scoping_to_a_barrel_narrows_the_readings_to_it_but_keeps_whole_lot_readings(): void
    {
        $this->page('?tab=analysis')->assertInertia(fn (AssertableInertia $page) => $page->where('table.meta.total', 4)->where('counts.analysis', 4));

        $this->page("?tab=analysis&vessel={$this->b2->getKey()}")->assertInertia(fn (AssertableInertia $page) => $page
            ->where('scope.vessel_id', (string) $this->b2->getKey())
            ->where('scope.vessel.name', 'B2')
            // B2's two readings plus the lot-level one, which applies to every barrel.
            ->where('table.meta.total', 3)
            ->where('counts.analysis', 3));
    }

    public function test_an_unknown_barrel_falls_back_to_the_whole_lot(): void
    {
        $this->page('?tab=analysis&vessel=does-not-exist')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->where('scope.vessel_id', null)
            ->where('scope.vessel', null)
            ->where('table.meta.total', 4));

        // A barrel that exists but does not hold this lot is no scope either.
        $stranger = $this->inTenantVessel();
        $this->page("?tab=analysis&vessel={$stranger}")->assertInertia(fn (AssertableInertia $page) => $page->where('scope.vessel_id', null)->where('table.meta.total', 4));
    }

    public function test_every_record_tab_filters_by_barrel_and_pages_on_the_server(): void
    {
        $this->actingAsTenant($this->tenant);
        $by = $this->cellar->getKey();
        foreach ([$this->b1, $this->b2] as $vessel) {
            for ($i = 0; $i < 12; $i++) {
                CellarAddition::create(['wine_lot_id' => $this->lot->getKey(), 'vessel_id' => $vessel->getKey(), 'created_by_id' => $by, 'name' => "Add {$vessel->name}-{$i}", 'quantity' => 1, 'unit' => 'g']);
            }
            CellarProcess::create(['wine_lot_id' => $this->lot->getKey(), 'vessel_id' => $vessel->getKey(), 'date' => '2026-10-05', 'kind' => "Rack {$vessel->name}", 'created_by_id' => $by]);
            CellarTastingNote::create(['wine_lot_id' => $this->lot->getKey(), 'vessel_id' => $vessel->getKey(), 'date' => '2026-10-06', 'score' => 88, 'created_by_id' => $by]);
        }
        $this->forgetTenant();

        // Whole lot: 24 additions, paged 10 at a time.
        $this->page('?tab=additions&per_page=10&page=3')->assertInertia(fn (AssertableInertia $page) => $page
            ->where('table.meta.total', 24)->where('table.meta.last_page', 3)->where('table.meta.current_page', 3)->has('table.data', 4));

        // One barrel: only its own.
        $v = $this->b1->getKey();
        $this->page("?tab=additions&vessel={$v}")->assertInertia(fn (AssertableInertia $page) => $page->where('table.meta.total', 12));
        $this->page("?tab=processes&vessel={$v}")->assertInertia(fn (AssertableInertia $page) => $page->where('table.meta.total', 1)->where('table.data.0.kind', 'Rack B1'));
        $this->page("?tab=tasting&vessel={$v}")->assertInertia(fn (AssertableInertia $page) => $page->where('table.meta.total', 1));
        $this->page('?tab=tasting')->assertInertia(fn (AssertableInertia $page) => $page->where('table.meta.total', 2));
    }

    public function test_only_the_active_tabs_table_is_sent_and_the_lot_payload_stays_light(): void
    {
        $this->page()->assertOk()->assertInertia(function (AssertableInertia $page): void {
            $props = $page->toArray()['props'];
            $this->assertSame([], $props['lot']['analyses'], 'readings arrive through the Analysis tab, not with the lot');
            $this->assertSame([], $props['lot']['additions']);
            $this->assertSame([], $props['lot']['transfers']);
            $this->assertNull($props['bottlings']);
        });
    }

    public function test_the_overview_journey_is_paged_and_filterable_by_kind(): void
    {
        $this->page('?tab=overview&kind=analysis')->assertInertia(function (AssertableInertia $page): void {
            $page->where('scope.kind', 'analysis');
            $types = array_unique(array_column($page->toArray()['props']['table']['data'], 'type'));
            $this->assertSame(['analysis'], array_values($types));
        });

        $this->page('?tab=overview&per_page=10')->assertInertia(fn (AssertableInertia $page) => $page->where('table.meta.per_page', 10));
    }

    private function inTenantVessel(): string
    {
        $this->actingAsTenant($this->tenant);
        $v = Vessel::create(['name' => 'Elsewhere', 'type' => 'TANK', 'capacity_liters' => '500.000']);
        $this->forgetTenant();

        return (string) $v->getKey();
    }

    public function test_in_a_barrels_view_the_barrels_tables_show_only_that_barrel(): void
    {
        $v = $this->b2->getKey();

        $this->page("?tab=barrels&vessel={$v}")->assertInertia(fn (AssertableInertia $page) => $page
            ->where('table.meta.total', 1)
            ->where('table.data.0.name', 'B2')
            ->where('counts.barrels', 1)
            ->where('barrels.combined.barrels', 1)
            ->where('barrels.combined.volume', '100.000'));

        // The overview's compact table is narrowed the same way; the whole lot still lists all three.
        $this->page("?vessel={$v}")->assertInertia(fn (AssertableInertia $page) => $page->has('barrels.rows', 1)->where('barrels.rows.0.name', 'B2'));
        $this->page()->assertInertia(fn (AssertableInertia $page) => $page->has('barrels.rows', 3)->where('counts.barrels', 3));
    }

    /* ---- Deleting several vessels from the map ----------------------- */

    private function admin(): User
    {
        return $this->createMember($this->tenant, [TenantRole::Admin]);
    }

    public function test_bulk_delete_removes_empty_vessels_and_keeps_those_holding_wine(): void
    {
        $this->actingAsTenant($this->tenant);
        $empty1 = Vessel::create(['name' => 'E1', 'type' => 'BARREL', 'capacity_liters' => '225.000']);
        $empty2 = Vessel::create(['name' => 'E2', 'type' => 'BARREL', 'capacity_liters' => '225.000']);
        $this->forgetTenant();

        $this->actingAs($this->admin())->withSession([ActiveTenantSession::KEY => $this->tenant->getKey()])
            ->delete('/cellar/vessels', ['ids' => [$empty1->getKey(), $empty2->getKey(), $this->b1->getKey()]])
            ->assertSessionHas('success', fn (string $m): bool => str_contains($m, '2') && str_contains($m, 'B1'));

        $this->actingAsTenant($this->tenant);
        $this->assertSame(['B1', 'B2', 'B3'], Vessel::query()->orderBy('name')->pluck('name')->all(), 'only the two empty ones went');
        $this->assertTrue(AuditLog::query()->where('action', 'vessel.bulk_deleted')->exists());
    }

    public function test_bulk_delete_of_only_full_vessels_deletes_nothing_and_says_why(): void
    {
        $this->actingAs($this->admin())->withSession([ActiveTenantSession::KEY => $this->tenant->getKey()])
            ->delete('/cellar/vessels', ['ids' => [$this->b1->getKey(), $this->b2->getKey()]])
            ->assertSessionHas('error', fn (string $m): bool => str_contains($m, 'B1') && str_contains($m, 'B2'));

        $this->actingAsTenant($this->tenant);
        $this->assertSame(3, Vessel::query()->count());
    }

    public function test_bulk_delete_needs_the_delete_permission_and_a_selection(): void
    {
        $session = [ActiveTenantSession::KEY => $this->tenant->getKey()];

        // The cellar role manages vessels but cannot delete them.
        $this->actingAs($this->cellar)->withSession($session)->delete('/cellar/vessels', ['ids' => [$this->b3->getKey()]])->assertForbidden();
        $this->actingAs($this->admin())->withSession($session)->delete('/cellar/vessels', ['ids' => []])->assertSessionHasErrors('ids');
    }

    public function test_bulk_delete_ignores_other_wineries_vessels(): void
    {
        $other = $this->createTenant();
        $this->actingAsTenant($other);
        $stranger = Vessel::create(['name' => 'Theirs', 'type' => 'TANK', 'capacity_liters' => '500.000']);
        $this->forgetTenant();

        $this->actingAs($this->admin())->withSession([ActiveTenantSession::KEY => $this->tenant->getKey()])
            ->delete('/cellar/vessels', ['ids' => [$stranger->getKey()]]);

        $this->actingAsTenant($other);
        $this->assertSame(1, Vessel::query()->count(), 'another winery\'s vessel is untouched');
    }
}
