<?php

declare(strict_types=1);

namespace Tests\Feature\Cellar;

use App\Enums\TenantRole;
use App\Models\AuditLog;
use App\Models\Bottling;
use App\Models\CellarAddition;
use App\Models\CellarAnalysis;
use App\Models\CellarProcess;
use App\Models\CellarTastingNote;
use App\Models\CellarTransfer;
use App\Models\EnologicalProduct;
use App\Models\InventoryItem;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Vessel;
use App\Models\VesselLot;
use App\Models\WineLot;
use App\Models\WineLotGrape;
use App\Services\Cellar\VesselVolumeSync;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Symfony\Component\HttpFoundation\Response;
use Tests\Concerns\InteractsWithTenancy;
use Tests\TestCase;

/** Map editing, vessel detail and lot volume operations (parity slice 1c). */
class CellarVesselsAndLotsTest extends TestCase
{
    use InteractsWithTenancy;
    use RefreshDatabase;

    private Tenant $tenant;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = $this->createTenant();
        $this->admin = $this->createMember($this->tenant, [TenantRole::Admin]);
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
    private function vessel(string $name, string $capacity = '1000.000', array $extra = []): Vessel
    {
        return Vessel::create(['name' => $name, 'type' => 'TANK', 'capacity_liters' => $capacity] + $extra);
    }

    /**
     * @param  array<string, string>  $holdings  vessel id → litres
     * @param  array<string, mixed>  $extra
     */
    private function lot(string $number, string $volume, array $holdings = [], array $extra = []): WineLot
    {
        $lot = WineLot::create(array_merge([
            'lot_number' => $number, 'name' => 'Lot '.$number, 'grape_variety' => 'Plavac', 'vintage' => '2026',
            'wine_type' => 'RED', 'initial_volume' => $volume, 'current_volume' => $volume,
        ], $extra));
        foreach ($holdings as $vesselId => $litres) {
            VesselLot::create(['vessel_id' => $vesselId, 'wine_lot_id' => $lot->getKey(), 'volume' => $litres]);
            app(VesselVolumeSync::class)->syncMany([$vesselId]);
        }

        return $lot;
    }

    public function test_duplicate_continues_numbering_with_padding_and_offsets_the_map(): void
    {
        $source = $this->inTenant(function () {
            $this->vessel('F10');

            return $this->vessel('F09', '2500.000', ['material' => 'Stainless', 'room' => 'Hall', 'position_x' => 100, 'position_y' => 40, 'map_width' => 60, 'map_height' => 80]);
        });

        $this->api('POST', 'vessels/duplicate', ['vessel_ids' => [$source->getKey()], 'count' => 2])
            ->assertCreated()
            ->assertJsonPath('data.0.name', 'F11')
            ->assertJsonPath('data.1.name', 'F12')
            ->assertJsonPath('data.0.capacity_liters', '2500.000')
            ->assertJsonPath('data.0.room', 'Hall')
            ->assertJsonPath('data.0.position_x', 120)
            ->assertJsonPath('data.1.position_x', 140);

        $this->inTenant(fn () => $this->assertTrue(AuditLog::query()->where('action', 'vessel.duplicated')->exists()));
    }

    public function test_remove_from_map_clears_the_position(): void
    {
        $v = $this->inTenant(fn () => $this->vessel('F1', '1000.000', ['position_x' => 10, 'position_y' => 20]));

        $this->api('PATCH', 'vessels/layout', ['updates' => [['id' => $v->getKey(), 'position_x' => null, 'position_y' => null]]])->assertSuccessful();

        $this->inTenant(function () use ($v): void {
            $v->refresh();
            $this->assertNull($v->position_x);
            $this->assertNull($v->position_y);
        });
    }

    public function test_bulk_assign_fills_or_splits_and_grows_the_lot_for_new_wine(): void
    {
        [$lot, $a, $b, $c] = $this->inTenant(function () {
            $a = $this->vessel('B1', '225.000');
            $b = $this->vessel('B2', '225.000');
            $c = $this->vessel('B3', '225.000');

            return [$this->lot('LOT-2026-001', '500.000'), $a, $b, $c];
        });
        $ids = [$a->getKey(), $b->getKey(), $c->getKey()];

        // Dry run: existing unallocated wine (500 L) filled in name order.
        $this->api('POST', "wine-lots/{$lot->getKey()}/vessels/bulk", ['vessel_ids' => $ids, 'mode' => 'fill', 'source' => 'existing', 'dry_run' => true])
            ->assertOk()
            ->assertJsonPath('plan.total', '500.000')
            ->assertJsonPath('plan.lines.0.volume', '225.000')
            ->assertJsonPath('plan.lines.2.volume', '50.000');
        $this->inTenant(fn () => $this->assertSame(0, VesselLot::query()->count(), 'dry run writes nothing'));

        $this->api('POST', "wine-lots/{$lot->getKey()}/vessels/bulk", ['vessel_ids' => $ids, 'mode' => 'split', 'source' => 'existing', 'volume' => 300])
            ->assertOk()
            ->assertJsonPath('plan.lines.0.volume', '100.000');

        $this->api('POST', "wine-lots/{$lot->getKey()}/vessels/bulk", ['vessel_ids' => $ids, 'mode' => 'split', 'source' => 'existing', 'volume' => 300])
            ->assertUnprocessable()->assertJsonValidationErrors(['volume']); // only 200 L left unallocated

        $this->api('POST', "wine-lots/{$lot->getKey()}/vessels/bulk", ['vessel_ids' => $ids, 'mode' => 'fill', 'source' => 'new', 'volume' => 375])
            ->assertOk()
            ->assertJsonPath('plan.lot_grows_by', '375.000');

        $this->inTenant(function () use ($lot, $a, $c): void {
            $lot->refresh();
            $this->assertSame('875.000', (string) $lot->current_volume);
            $this->assertSame('875.000', (string) $lot->initial_volume);
            $this->assertSame('225.000', (string) $a->refresh()->current_volume);
            $this->assertSame('225.000', (string) $c->refresh()->current_volume);
            $this->assertTrue(AuditLog::query()->where('action', 'wine_lot.assigned_to_vessels')->exists());
        });

        $this->api('POST', "wine-lots/{$lot->getKey()}/vessels/bulk", ['vessel_ids' => $ids, 'mode' => 'split', 'source' => 'new', 'volume' => 30])
            ->assertUnprocessable()->assertJsonValidationErrors(['volume']); // vessels are full
    }

    public function test_vessel_detail_lists_its_analyses_tastings_and_projected_so2(): void
    {
        [$vessel, $lot] = $this->inTenant(function () {
            $v = $this->vessel('F11');
            $lot = $this->lot('LOT-2026-001', '500.000', [$v->getKey() => '500.000']);
            $by = $this->admin->getKey();
            CellarAnalysis::create(['wine_lot_id' => $lot->getKey(), 'vessel_id' => $v->getKey(), 'created_by_id' => $by, 'date' => now()->subDays(2), 'free_so2' => 20, 'ph' => 3.5]);
            CellarAnalysis::create(['wine_lot_id' => $lot->getKey(), 'created_by_id' => $by, 'date' => now()->subDay(), 'free_so2' => 30]); // lot-level: not this vessel's
            CellarTastingNote::create(['wine_lot_id' => $lot->getKey(), 'vessel_id' => $v->getKey(), 'created_by_id' => $by, 'date' => now(), 'score' => 88, 'nose' => 'Cherry']);
            $product = EnologicalProduct::create(['name' => 'SO2 solution', 'category' => 'SO2', 'unit' => 'ml', 'current_stock' => '1000.000', 'so2_uplift_per_unit' => '50.0000']);
            CellarAddition::create(['wine_lot_id' => $lot->getKey(), 'vessel_id' => $v->getKey(), 'enological_product_id' => $product->getKey(), 'created_by_id' => $by, 'name' => 'SO2', 'quantity' => '100.000', 'unit' => 'ml']);

            return [$v, $lot];
        });

        $data = $this->api('GET', "vessels/{$vessel->getKey()}")->assertOk()->json('data');

        $this->assertCount(1, $data['analyses']);
        $this->assertSame('20.00', $data['analyses'][0]['free_so2']);
        $this->assertSame(88, $data['tasting_notes'][0]['score']);
        $this->assertSame($lot->getKey(), $data['so2'][0]['wine_lot_id']);
        $this->assertTrue($data['so2'][0]['reading_in_vessel']);
        $this->assertEquals(30.0, $data['so2'][0]['projected_free_so2']); // 20 + 100 ml × 50 / 500 L
    }

    public function test_editing_grapes_keeps_blend_percentages_and_harvest_links_and_recomputes_cost(): void
    {
        $lot = $this->inTenant(function () {
            $lot = $this->lot('LOT-2026-001', '500.000');
            WineLotGrape::create(['wine_lot_id' => $lot->getKey(), 'grape_variety' => 'Plavac', 'percentage' => '60.00', 'harvest_entry_id' => null, 'weight_kg' => '600.000', 'price_per_kg' => 100]);
            WineLotGrape::create(['wine_lot_id' => $lot->getKey(), 'grape_variety' => 'Syrah', 'percentage' => '40.00', 'weight_kg' => '400.000', 'price_per_kg' => 150]);

            return $lot;
        });

        $this->api('PATCH', "wine-lots/{$lot->getKey()}", ['grapes' => [
            ['grape_variety' => 'Plavac', 'price_per_kg' => 120, 'weight_kg' => 600],
            ['grape_variety' => 'Merlot', 'price_per_kg' => 200, 'weight_kg' => 100],
        ]])->assertOk();

        $this->inTenant(function () use ($lot): void {
            $lot->refresh();
            $plavac = $lot->grapes()->where('grape_variety', 'Plavac')->sole();
            $this->assertSame('60.00', (string) $plavac->percentage, 'percentage kept');
            $this->assertNull($lot->grapes()->where('grape_variety', 'Syrah')->first(), 'removed variety deleted');
            $this->assertSame(92000, $lot->grape_cost?->getMinorAmount()); // 120×600 + 200×100
            $this->assertSame('700.000', (string) $lot->harvest_weight_kg);
            $this->assertSame('Plavac, Merlot', $lot->grape_variety);
        });
    }

    public function test_deleting_a_lot_frees_vessels_and_takes_bottles_back_out_of_stock(): void
    {
        [$lot, $vessel, $item] = $this->inTenant(function () {
            $v = $this->vessel('F11');
            $item = InventoryItem::create(['name' => 'Plavac 2026', 'sku' => 'PLV26', 'category' => 'FINISHED', 'unit' => 'bottles', 'current_stock' => '0']);

            return [$this->lot('LOT-2026-001', '500.000', [$v->getKey() => '500.000']), $v, $item];
        });

        $this->api('POST', "wine-lots/{$lot->getKey()}/bottlings", ['bottle_count' => 100, 'inventory_item_id' => $item->getKey()])->assertCreated();
        $this->inTenant(fn () => $this->assertSame('100.000', (string) $item->refresh()->current_stock));

        $this->api('DELETE', "wine-lots/{$lot->getKey()}")->assertNoContent();

        $this->inTenant(function () use ($lot, $vessel, $item): void {
            $this->assertNull(WineLot::query()->find($lot->getKey()));
            $this->assertSame('0.000', (string) $item->refresh()->current_stock);
            $this->assertSame('0.000', (string) $vessel->refresh()->current_volume);
            $this->assertSame('AVAILABLE', $vessel->status->value);
            $log = AuditLog::query()->where('action', 'wine_lot.deleted')->sole();
            $this->assertSame('LOT-2026-001', $log->metadata['lot_number'] ?? null);
        });
    }

    public function test_cellar_role_cannot_delete_a_lot(): void
    {
        $cellar = $this->createMember($this->tenant, [TenantRole::Cellar]);
        $lot = $this->inTenant(fn () => $this->lot('LOT-2026-001', '100.000'));
        Sanctum::actingAs($cellar);

        $this->deleteJson("/api/v1/wine-lots/{$lot->getKey()}", [], $this->tenantHeader($this->tenant))->assertForbidden();
    }

    public function test_adjust_volume_spreads_proportionally_and_records_the_reason(): void
    {
        [$lot, $a, $b] = $this->inTenant(function () {
            $a = $this->vessel('A');
            $b = $this->vessel('B');

            return [$this->lot('LOT-2026-001', '400.000', [$a->getKey() => '300.000', $b->getKey() => '100.000']), $a, $b];
        });

        $this->api('POST', "wine-lots/{$lot->getKey()}/adjust-volume", ['delta' => -8, 'reason' => 'Evaporation'])->assertOk()
            ->assertJsonPath('data.current_volume', '392.000');

        $this->inTenant(function () use ($a, $b): void {
            $this->assertSame('294.000', (string) $a->refresh()->current_volume);
            $this->assertSame('98.000', (string) $b->refresh()->current_volume);
            $losses = CellarProcess::query()->where('kind', 'Loss')->orderBy('volume')->pluck('volume')->map(fn ($v) => (string) $v)->all();
            $this->assertSame(['2.000', '6.000'], $losses);
            $this->assertSame('Evaporation', CellarProcess::query()->value('note'));
            $this->assertTrue(AuditLog::query()->where('action', 'wine_lot.volume_adjusted')->exists());
        });

        $this->api('POST', "wine-lots/{$lot->getKey()}/adjust-volume", ['delta' => -150, 'vessel_id' => $b->getKey()])
            ->assertUnprocessable()->assertJsonValidationErrors(['delta']);
    }

    public function test_blend_in_moves_wine_mixes_grapes_and_carries_cost(): void
    {
        [$lot, $mine, $source, $other] = $this->inTenant(function () {
            $mine = $this->vessel('T1', '1000.000');
            $source = $this->vessel('T2', '1000.000');
            $lot = $this->lot('LOT-2026-001', '600.000', [$mine->getKey() => '600.000'], ['grape_variety' => 'Plavac']);
            WineLotGrape::create(['wine_lot_id' => $lot->getKey(), 'grape_variety' => 'Plavac', 'percentage' => '100.00']);
            $other = $this->lot('LOT-2026-002', '400.000', [$source->getKey() => '400.000'], ['grape_variety' => 'Syrah', 'grape_cost' => 80000]);

            return [$lot, $mine, $source, $other];
        });

        $this->api('POST', "wine-lots/{$lot->getKey()}/blend-in", [
            'from_vessel_id' => $source->getKey(), 'to_vessel_id' => $mine->getKey(), 'volume' => 200,
        ])->assertOk()->assertJsonPath('data.current_volume', '800.000');

        $this->inTenant(function () use ($lot, $other, $source, $mine): void {
            $lot->refresh();
            $this->assertSame('200.000', (string) $other->refresh()->current_volume);
            $this->assertSame('200.000', (string) $source->refresh()->current_volume);
            $this->assertSame('800.000', (string) $mine->refresh()->current_volume);
            $this->assertSame('75.00', (string) $lot->grapes()->where('grape_variety', 'Plavac')->value('percentage'));
            $this->assertSame('25.00', (string) $lot->grapes()->where('grape_variety', 'Syrah')->value('percentage'));
            $this->assertSame(40000, $lot->carried_cost); // 80000 / 400 L × 200 L
            $this->assertSame('BLEND', CellarTransfer::query()->sole()->type->value);
            $this->assertTrue(AuditLog::query()->where('action', 'wine_lot.blended_in')->exists());
        });
    }

    public function test_lot_list_with_counts_feeds_the_bottled_archive(): void
    {
        $lot = $this->inTenant(function () {
            $lot = $this->lot('LOT-2026-001', '0.000', [], ['status' => 'BOTTLED']);
            CellarAnalysis::create(['wine_lot_id' => $lot->getKey(), 'created_by_id' => $this->admin->getKey(), 'date' => now(), 'ph' => 3.4]);
            Bottling::create(['wine_lot_id' => $lot->getKey(), 'created_by_id' => $this->admin->getKey(), 'bottle_count' => 600, 'bottle_volume_ml' => 750, 'volume_used' => '450.000', 'date' => now()]);

            return $lot;
        });

        $this->api('GET', 'wine-lots?status=BOTTLED&with_counts=1')
            ->assertOk()
            ->assertJsonPath('data.0.id', $lot->getKey())
            ->assertJsonPath('data.0.counts.analyses', 1)
            ->assertJsonPath('data.0.counts.bottlings', 1)
            ->assertJsonPath('data.0.latest_bottling.bottle_count', 600);
    }

    public function test_assign_and_unassign_are_audited(): void
    {
        [$lot, $v] = $this->inTenant(fn () => [$this->lot('LOT-2026-001', '100.000'), $this->vessel('F1')]);

        $this->api('POST', "wine-lots/{$lot->getKey()}/vessels", ['vessel_id' => $v->getKey(), 'volume' => 100])->assertOk();
        $vesselLotId = $this->inTenant(fn () => VesselLot::query()->value('id'));
        $this->api('DELETE', "wine-lots/{$lot->getKey()}/vessels/{$vesselLotId}")->assertOk();

        $this->inTenant(function (): void {
            $this->assertTrue(AuditLog::query()->where('action', 'wine_lot.assigned_to_vessel')->exists());
            $this->assertTrue(AuditLog::query()->where('action', 'wine_lot.unassigned_from_vessel')->exists());
        });
    }
}
