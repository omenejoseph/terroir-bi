<?php

declare(strict_types=1);

namespace Tests\Feature\Web\Cellar;

use App\Enums\TenantRole;
use App\Models\AuditLog;
use App\Models\CellarAddition;
use App\Models\CellarAnalysis;
use App\Models\CellarProcess;
use App\Models\CellarTastingNote;
use App\Models\CellarTransfer;
use App\Models\EnologicalProduct;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Vessel;
use App\Models\VesselLot;
use App\Models\WineLot;
use App\Services\Auth\ActiveTenantSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\Concerns\InteractsWithTenancy;
use Tests\TestCase;

/** Cellar deletes with Undo (Phase 3.8): delete, then put it back exactly as it was. */
class WebCellarUndoTest extends TestCase
{
    use InteractsWithTenancy;
    use RefreshDatabase;

    private Tenant $tenant;

    private User $cellar;

    private WineLot $lot;

    private Vessel $t1;

    private Vessel $t2;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-16 09:00:00');
        $this->tenant = $this->createTenant();
        $this->cellar = $this->createMember($this->tenant, [TenantRole::Cellar]);

        $this->inTenant(function (): void {
            $this->t1 = Vessel::create(['name' => 'T1', 'type' => 'TANK', 'capacity_liters' => '3000.000']);
            $this->t2 = Vessel::create(['name' => 'T2', 'type' => 'TANK', 'capacity_liters' => '3000.000']);
            $this->lot = WineLot::create(['lot_number' => 'R-1', 'name' => 'Ostro Crna', 'grape_variety' => 'Plavac', 'vintage' => '2023', 'wine_type' => 'RED', 'status' => 'AGING', 'initial_volume' => '2000.000', 'current_volume' => '2000.000']);
            VesselLot::create(['vessel_id' => $this->t1->getKey(), 'wine_lot_id' => $this->lot->getKey(), 'volume' => '2000.000']);
        });
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /**
     * @param  array<string, mixed>  $data
     * @return TestResponse<Response>
     */
    private function web(string $method, string $uri, array $data = [], ?User $as = null): TestResponse
    {
        return $this->actingAs($as ?? $this->cellar)->withSession([ActiveTenantSession::KEY => $this->tenant->getKey()])->from('/cellar/lots/'.$this->lot->getKey())->call($method, $uri, $data);
    }

    private function inTenant(callable $fn): mixed
    {
        $this->actingAsTenant($this->tenant);
        try {
            return $fn();
        } finally {
            $this->forgetTenant();
        }
    }

    private function deleteAndGetToken(string $uri): string
    {
        $this->web('DELETE', $uri)->assertRedirect();
        $token = session('undo');
        $this->assertIsString($token);

        return $token;
    }

    private function base(): string
    {
        return '/cellar/lots/'.$this->lot->getKey();
    }

    public function test_an_addition_comes_back_with_its_stock_drawn_again_and_its_original_date(): void
    {
        [$product, $addition] = $this->inTenant(function (): array {
            $p = EnologicalProduct::create(['name' => 'K2S2O5', 'category' => 'SO2', 'unit' => 'g', 'current_stock' => '100.000', 'cost_per_unit' => 2]);
            Carbon::setTestNow('2026-10-10 08:00:00');
            $this->web('POST', $this->base().'/additions', ['name' => 'K2S2O5', 'quantity' => 40, 'unit' => 'g', 'vessel_id' => $this->t1->getKey(), 'enological_product_id' => $p->getKey(), 'note' => 'SO₂ +10']);
            Carbon::setTestNow('2026-10-16 09:00:00');

            return [$p, CellarAddition::query()->firstOrFail()];
        });
        $this->inTenant(fn () => $this->assertSame('60.000', (string) $product->fresh()?->current_stock));

        $token = $this->deleteAndGetToken($this->base().'/additions/'.$addition->getKey());
        $this->inTenant(function () use ($product): void {
            $this->assertSame(0, CellarAddition::query()->count());
            $this->assertSame('100.000', (string) $product->fresh()?->current_stock);
        });

        $this->web('POST', '/cellar/undo/'.$token)->assertRedirect()->assertSessionHas('success');

        $this->inTenant(function () use ($product, $addition): void {
            $back = CellarAddition::query()->sole();
            $this->assertSame('60.000', (string) $product->fresh()?->current_stock);
            $this->assertSame($this->t1->getKey(), $back->vessel_id);
            $this->assertSame('SO₂ +10', $back->note);
            $this->assertSame($addition->total_cost?->minor, $back->total_cost?->minor);
            $this->assertSame('2026-10-10', $back->created_at?->toDateString());
            $this->assertTrue(AuditLog::query()->where('action', 'cellar.undo')->exists());
        });
    }

    public function test_an_undo_that_is_no_longer_possible_says_why_and_can_be_tried_again(): void
    {
        $product = $this->inTenant(function (): EnologicalProduct {
            $p = EnologicalProduct::create(['name' => 'Bentonite', 'category' => 'FINING', 'unit' => 'g', 'current_stock' => '50.000']);
            $this->web('POST', $this->base().'/additions', ['name' => 'Bentonite', 'quantity' => 50, 'unit' => 'g', 'enological_product_id' => $p->getKey()]);

            return $p;
        });
        $addition = $this->inTenant(fn () => CellarAddition::query()->sole());
        $token = $this->deleteAndGetToken($this->base().'/additions/'.$addition->getKey());
        // Meanwhile the stock is used elsewhere.
        $this->inTenant(fn () => $product->forceFill(['current_stock' => '10.000'])->save());

        $this->web('POST', '/cellar/undo/'.$token)->assertSessionHas('error');
        $this->inTenant(fn () => $this->assertSame(0, CellarAddition::query()->count()));

        $this->inTenant(fn () => $product->forceFill(['current_stock' => '80.000'])->save());
        $this->web('POST', '/cellar/undo/'.$token)->assertSessionHas('success');
        $this->inTenant(fn () => $this->assertSame(1, CellarAddition::query()->count()));
    }

    public function test_readings_processes_and_tasting_notes_come_back_as_they_were(): void
    {
        [$a, $p, $n] = $this->inTenant(fn (): array => [
            CellarAnalysis::create(['wine_lot_id' => $this->lot->getKey(), 'vessel_id' => $this->t1->getKey(), 'date' => '2026-10-01', 'free_so2' => 22, 'ph' => 3.45, 'note' => 'after racking', 'created_by_id' => $this->cellar->getKey()]),
            CellarProcess::create(['wine_lot_id' => $this->lot->getKey(), 'date' => '2026-10-02', 'kind' => 'Pump-over', 'volume' => 300, 'created_by_id' => $this->cellar->getKey()]),
            CellarTastingNote::create(['wine_lot_id' => $this->lot->getKey(), 'date' => '2026-10-03', 'nose' => 'cherry', 'score' => 88, 'created_by_id' => $this->cellar->getKey()]),
        ]);

        foreach ([['analyses', $a], ['processes', $p], ['tasting-notes', $n]] as [$path, $record]) {
            $token = $this->deleteAndGetToken("{$this->base()}/{$path}/{$record->getKey()}");
            $this->web('POST', '/cellar/undo/'.$token)->assertSessionHas('success');
        }

        $this->inTenant(function (): void {
            $a = CellarAnalysis::query()->sole();
            $this->assertSame('2026-10-01', $a->date->toDateString());
            $this->assertEqualsWithDelta(22.0, (float) $a->free_so2, 0.001);
            $this->assertEqualsWithDelta(3.45, (float) $a->ph, 0.001);
            $this->assertSame('after racking', $a->note);
            $this->assertSame($this->t1->getKey(), $a->vessel_id);
            $p = CellarProcess::query()->sole();
            $this->assertSame(['Pump-over', '2026-10-02'], [$p->kind, $p->date->toDateString()]);
            $n = CellarTastingNote::query()->sole();
            $this->assertSame(['cherry', 88, '2026-10-03'], [$n->nose, $n->score, $n->date->toDateString()]);
        });
    }

    public function test_a_rack_is_moved_again(): void
    {
        $this->web('POST', $this->base().'/transfers', ['type' => 'RACK', 'volume_liters' => 500, 'to_lot_id' => $this->lot->getKey(), 'from_vessel_id' => $this->t1->getKey(), 'to_vessel_id' => $this->t2->getKey()])->assertSessionHasNoErrors();
        $transfer = $this->inTenant(fn () => CellarTransfer::query()->sole());

        $token = $this->deleteAndGetToken($this->base().'/transfers/'.$transfer->getKey());
        $this->inTenant(fn () => $this->assertSame(['2000.000'], VesselLot::query()->pluck('volume')->map(fn ($v) => (string) $v)->all()));

        $this->web('POST', '/cellar/undo/'.$token)->assertSessionHas('success');
        $this->inTenant(function (): void {
            $this->assertSame(1, CellarTransfer::query()->count());
            $this->assertSame('1500.000', (string) VesselLot::query()->where('vessel_id', $this->t1->getKey())->value('volume'));
            $this->assertSame('500.000', (string) VesselLot::query()->where('vessel_id', $this->t2->getKey())->value('volume'));
        });
    }

    public function test_a_token_works_once_only_for_its_owner_and_expires(): void
    {
        $make = fn (): string => $this->deleteAndGetToken($this->base().'/processes/'.$this->inTenant(fn () => CellarProcess::create(['wine_lot_id' => $this->lot->getKey(), 'date' => '2026-10-02', 'kind' => 'Pump-over', 'created_by_id' => $this->cellar->getKey()]))->getKey());

        // Someone else can't use it — and trying doesn't spend it.
        $token = $make();
        $other = $this->createMember($this->tenant, [TenantRole::Cellar]);
        $this->web('POST', '/cellar/undo/'.$token, [], $other)->assertSessionHas('error');
        $this->web('POST', '/cellar/undo/'.$token)->assertSessionHas('success');
        // Once: a repeat is told it already worked, and puts nothing back twice.
        $this->web('POST', '/cellar/undo/'.$token)->assertSessionHas('success');
        $this->inTenant(fn () => $this->assertSame(1, CellarProcess::query()->count()));
        $this->web('POST', '/cellar/undo/'.$token, [], $other)->assertSessionHas('error');

        // Ten minutes.
        $this->inTenant(fn () => CellarProcess::query()->delete());
        $token = $make();
        $this->travel(11)->minutes();
        $this->web('POST', '/cellar/undo/'.$token)->assertSessionHas('error');
        $this->inTenant(fn () => $this->assertSame(0, CellarProcess::query()->count()));
    }

    public function test_deleting_a_lot_shows_what_goes_with_it_first(): void
    {
        $admin = $this->createMember($this->tenant, [TenantRole::Admin]);
        $this->inTenant(fn () => CellarAnalysis::create(['wine_lot_id' => $this->lot->getKey(), 'date' => '2026-10-01', 'free_so2' => 22, 'created_by_id' => $this->cellar->getKey()]));

        $this->web('GET', $this->base().'/delete-impact', [], $admin)->assertOk()->assertJson([
            'lot_number' => 'R-1', 'vessels' => ['T1'], 'analyses' => 1, 'additions' => 0, 'bottlings' => 0, 'bottles' => 0,
        ]);
        $this->web('GET', $this->base().'/delete-impact')->assertForbidden();
    }
}
