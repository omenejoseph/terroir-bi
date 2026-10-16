<?php

declare(strict_types=1);

namespace Tests\Feature\Cellar;

use App\Enums\TenantRole;
use App\Models\AuditLog;
use App\Models\BottlingVessel;
use App\Models\CellarAddition;
use App\Models\CellarTastingNote;
use App\Models\EnologicalProduct;
use App\Models\InventoryItem;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Vessel;
use App\Models\VesselLot;
use App\Models\WineLot;
use App\Services\Cellar\VesselVolumeSync;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Symfony\Component\HttpFoundation\Response;
use Tests\Concerns\InteractsWithTenancy;
use Tests\TestCase;

/** Tasting notes and reports, addition edits, bottling (parity slice 1e). */
class CellarTastingsAndBottlingTest extends TestCase
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
     * @param  array<string, string>  $holdings
     */
    private function lot(string $number, string $volume, array $holdings = []): WineLot
    {
        $lot = WineLot::create([
            'lot_number' => $number, 'name' => 'Lot '.$number, 'grape_variety' => 'Plavac', 'vintage' => '2025',
            'wine_type' => 'RED', 'initial_volume' => $volume, 'current_volume' => $volume,
        ]);
        foreach ($holdings as $vesselId => $litres) {
            VesselLot::create(['vessel_id' => $vesselId, 'wine_lot_id' => $lot->getKey(), 'volume' => $litres]);
            app(VesselVolumeSync::class)->syncMany([$vesselId]);
        }

        return $lot;
    }

    public function test_a_tasting_note_can_be_corrected(): void
    {
        [$lot, $note] = $this->inTenant(function () {
            $lot = $this->lot('LOT-2025-001', '100.000');
            $note = CellarTastingNote::create(['wine_lot_id' => $lot->getKey(), 'created_by_id' => $this->admin->getKey(), 'date' => now(), 'score' => 80]);

            return [$lot, $note];
        });

        $this->api('PATCH', "wine-lots/{$lot->getKey()}/tasting-notes/{$note->getKey()}", ['score' => 86, 'nose' => 'Violets'])->assertOk();

        $this->inTenant(function () use ($note): void {
            $note->refresh();
            $this->assertSame(86, $note->score);
            $this->assertSame('Violets', $note->nose);
        });
    }

    public function test_tasting_report_lifecycle_syncs_lot_notes(): void
    {
        [$a, $b, $c] = $this->inTenant(fn () => [$this->lot('LOT-2025-001', '100.000'), $this->lot('LOT-2025-002', '100.000'), $this->lot('LOT-2025-003', '100.000')]);

        $id = $this->api('POST', 'tasting-reports', [
            'title' => 'October panel',
            'date' => '2026-10-10',
            'wines' => [
                ['wine_lot_id' => $a->getKey(), 'score' => 90, 'nose' => 'Cherry'],
                ['wine_lot_id' => $b->getKey(), 'score' => 80],
            ],
        ])->assertCreated()
            ->assertJsonPath('data.wine_count', 2)
            ->assertJsonPath('data.average_score', 85)
            ->assertJsonPath('data.author.name', $this->admin->fullName())
            ->json('data.id');

        $this->inTenant(fn () => $this->assertSame(2, CellarTastingNote::query()->where('tasting_report_id', $id)->count()));

        $this->api('PATCH', "tasting-reports/{$id}", ['wines' => [
            ['wine_lot_id' => $a->getKey(), 'score' => 92],
            ['wine_lot_id' => $c->getKey(), 'score' => 70],
        ]])->assertOk()->assertJsonPath('data.wine_count', 2);

        $this->inTenant(function () use ($a, $b, $c): void {
            $this->assertSame(92, CellarTastingNote::query()->where('wine_lot_id', $a->getKey())->value('score'));
            $this->assertNull(CellarTastingNote::query()->where('wine_lot_id', $b->getKey())->first(), 'removed wine loses its note');
            $this->assertSame(70, CellarTastingNote::query()->where('wine_lot_id', $c->getKey())->value('score'));
        });

        $this->api('GET', 'tasting-reports')->assertOk()->assertJsonPath('data.0.wines_preview', ['Lot LOT-2025-001', 'Lot LOT-2025-003']);
        $this->api('GET', "tasting-reports/{$id}")->assertOk()->assertJsonPath('data.wines.0.wine_lot.lot_number', 'LOT-2025-001');

        $this->api('DELETE', "tasting-reports/{$id}")->assertNoContent();
        $this->inTenant(fn () => $this->assertSame(0, CellarTastingNote::query()->count()));
    }

    public function test_correcting_an_addition_moves_stock_between_products_and_may_overdraw_the_new_one(): void
    {
        [$lot, $addition, $first, $second] = $this->inTenant(function () {
            $lot = $this->lot('LOT-2025-001', '100.000');
            $first = EnologicalProduct::create(['name' => 'Bentonite A', 'category' => 'FINING', 'unit' => 'g', 'current_stock' => '70.000', 'cost_per_unit' => 2]);
            $second = EnologicalProduct::create(['name' => 'Bentonite B', 'category' => 'FINING', 'unit' => 'g', 'current_stock' => '40.000']);
            $addition = CellarAddition::create(['wine_lot_id' => $lot->getKey(), 'enological_product_id' => $first->getKey(), 'created_by_id' => $this->admin->getKey(), 'name' => 'Bentonite', 'quantity' => '30.000', 'unit' => 'g', 'cost_per_unit' => 2, 'total_cost' => 60]);

            return [$lot, $addition, $first, $second];
        });

        $this->api('PATCH', "wine-lots/{$lot->getKey()}/additions/{$addition->getKey()}", ['quantity' => 50])->assertOk();
        $this->inTenant(function () use ($first, $addition): void {
            $this->assertSame('50.000', (string) $first->refresh()->current_stock); // 70 + 30 − 50
            $this->assertSame(100, $addition->refresh()->total_cost?->getMinorAmount());
        });

        $this->api('PATCH', "wine-lots/{$lot->getKey()}/additions/{$addition->getKey()}", ['enological_product_id' => $second->getKey(), 'quantity' => 60])
            ->assertOk();
        $this->inTenant(function () use ($first, $second): void {
            $this->assertSame('100.000', (string) $first->refresh()->current_stock, 'the old product gets its 50 back');
            $this->assertSame('-20.000', (string) $second->refresh()->current_stock, '40 on the shelf, 60 used');
        });

        $this->api('PATCH', "wine-lots/{$lot->getKey()}/additions/{$addition->getKey()}", ['enological_product_id' => $second->getKey(), 'quantity' => 40])->assertOk();
        $this->inTenant(function () use ($first, $second): void {
            $this->assertSame('100.000', (string) $first->refresh()->current_stock);
            $this->assertSame('0.000', (string) $second->refresh()->current_stock);
        });
    }

    public function test_bottling_into_a_new_item_and_reversing_puts_wine_back_in_its_vessels(): void
    {
        [$lot, $a, $b] = $this->inTenant(function () {
            $a = Vessel::create(['name' => 'A', 'type' => 'TANK', 'capacity_liters' => '1000.000']);
            $b = Vessel::create(['name' => 'B', 'type' => 'TANK', 'capacity_liters' => '1000.000']);

            return [$this->lot('LOT-2025-001', '900.000', [$a->getKey() => '600.000', $b->getKey() => '300.000']), $a, $b];
        });

        $this->api('POST', "wine-lots/{$lot->getKey()}/bottlings", [
            'bottle_count' => 1000,
            'new_item' => ['name' => 'Plavac 2025', 'sku' => 'PLV25', 'sales_unit' => 'cases', 'bottles_per_case' => 6, 'default_price' => 1500],
        ])->assertCreated()->assertJsonPath('data.current_volume', '150.000');

        $item = $this->inTenant(fn () => InventoryItem::query()->where('sku', 'PLV25')->sole());
        $this->assertSame('1000.000', (string) $item->current_stock);
        $this->assertSame('FINISHED', $item->category->value);

        $bottlingId = $this->inTenant(fn () => $lot->bottlings()->value('id'));
        $this->inTenant(fn () => $this->assertSame(2, BottlingVessel::query()->count()));

        $this->api('DELETE', "wine-lots/{$lot->getKey()}/bottlings/{$bottlingId}")->assertOk()->assertJsonPath('data.current_volume', '900.000');

        $this->inTenant(function () use ($a, $b, $item): void {
            $this->assertSame('600.000', (string) $a->refresh()->current_volume);
            $this->assertSame('300.000', (string) $b->refresh()->current_volume);
            $this->assertSame('0.000', (string) $item->refresh()->current_stock);
            $this->assertTrue(AuditLog::query()->where('action', 'bottling.created')->exists());
            $this->assertTrue(AuditLog::query()->where('action', 'bottling.reversed')->exists());
        });
    }

    public function test_new_item_sku_must_be_unique(): void
    {
        $lot = $this->inTenant(function () {
            InventoryItem::create(['name' => 'X', 'sku' => 'PLV25', 'category' => 'FINISHED', 'unit' => 'bottles']);

            return $this->lot('LOT-2025-001', '100.000');
        });

        $this->api('POST', "wine-lots/{$lot->getKey()}/bottlings", ['bottle_count' => 10, 'new_item' => ['name' => 'Y', 'sku' => 'PLV25']])
            ->assertUnprocessable()->assertJsonValidationErrors(['new_item.sku']);
    }
}
