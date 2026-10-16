<?php

declare(strict_types=1);

namespace Tests\Feature\Cellar;

use App\Enums\TenantRole;
use App\Models\AuditLog;
use App\Models\CellarActivity;
use App\Models\CellarActivityRound;
use App\Models\CellarAddition;
use App\Models\CellarAnalysis;
use App\Models\EnologicalProduct;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Vessel;
use App\Models\VesselLot;
use App\Models\WineLot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Symfony\Component\HttpFoundation\Response;
use Tests\Concerns\InteractsWithTenancy;
use Tests\TestCase;

/** Cellar › SO₂: board, preview, apply, plan-for-team, calculators. */
class CellarSo2Test extends TestCase
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
    private function api(string $method, string $uri, array $body = [], ?User $as = null): TestResponse
    {
        Sanctum::actingAs($as ?? $this->admin);

        return $this->json($method, '/api/v1/'.$uri, $body, $this->tenantHeader($this->tenant));
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function keyed(mixed $rows, string $key): array
    {
        $out = [];
        foreach ((array) $rows as $row) {
            $row = (array) $row;
            $out[(string) $row[$key]] = $row;
        }

        return $out;
    }

    /**
     * Lot "Plavac" (red) in F11 (600 L, stainless) and B1 (400 L, oak barrel);
     * F11 read 18 mg/L free yesterday, the lot-level reading is 22 (5 days ago).
     *
     * @return array{WineLot, Vessel, Vessel, EnologicalProduct}
     */
    private function cellar(string $productUnit = 'g', ?string $uplift = null, string $stock = '1000.000'): array
    {
        return $this->inTenant(function () use ($productUnit, $uplift, $stock): array {
            $f11 = Vessel::create(['name' => 'F11', 'type' => 'TANK', 'material' => 'Stainless', 'capacity_liters' => '1000.000']);
            $b1 = Vessel::create(['name' => 'B1', 'type' => 'BARREL', 'material' => 'Oak', 'capacity_liters' => '500.000']);
            $lot = WineLot::create([
                'lot_number' => 'LOT-2026-001', 'name' => 'Plavac', 'grape_variety' => 'Plavac', 'vintage' => '2026',
                'wine_type' => 'RED', 'status' => 'AGING', 'initial_volume' => '1000.000', 'current_volume' => '1000.000',
            ]);
            VesselLot::create(['vessel_id' => $f11->getKey(), 'wine_lot_id' => $lot->getKey(), 'volume' => '600.000']);
            VesselLot::create(['vessel_id' => $b1->getKey(), 'wine_lot_id' => $lot->getKey(), 'volume' => '400.000']);

            $by = $this->admin->getKey();
            CellarAnalysis::create(['wine_lot_id' => $lot->getKey(), 'created_by_id' => $by, 'date' => '2026-10-09 10:00:00', 'free_so2' => 22, 'total_so2' => 160, 'ph' => 3.5, 'residual_sugar' => 1.2]);
            CellarAnalysis::create(['wine_lot_id' => $lot->getKey(), 'vessel_id' => $f11->getKey(), 'created_by_id' => $by, 'date' => '2026-10-13 10:00:00', 'free_so2' => 18]);
            CellarAnalysis::create(['wine_lot_id' => $lot->getKey(), 'vessel_id' => $f11->getKey(), 'created_by_id' => $by, 'date' => '2026-09-01 10:00:00', 'free_so2' => 40]); // older, ignored

            $product = EnologicalProduct::create([
                'name' => 'K2S2O5', 'category' => 'SO2', 'unit' => $productUnit, 'current_stock' => $stock,
                'cost_per_unit' => 3, 'so2_uplift_per_unit' => $uplift,
            ]);

            return [$lot, $f11, $b1, $product];
        });
    }

    public function test_board_shows_latest_reading_per_vessel_with_status_and_compliance(): void
    {
        [$lot, $f11, $b1] = $this->cellar();
        $this->inTenant(fn () => EnologicalProduct::create(['name' => 'Bentonite', 'category' => 'FINING', 'unit' => 'g', 'current_stock' => '1.000']));

        $board = $this->api('GET', 'cellar/so2')->assertOk()->json('data');

        $row = $board['lots'][0];
        $this->assertSame($lot->getKey(), $row['id']);
        // The lot row shows its worst vessel: F11's own 18 (B1 inherits the lot-level 22).
        $this->assertEquals(18.0, $row['free_so2']);
        $this->assertSame('low', $row['so2_status']);
        $this->assertSame(1, $row['days_since']);
        $this->assertEquals(0.36, $row['molecular']);             // 18 / (1 + 10^1.69)
        $this->assertEquals(['limit' => 150, 'compliant' => false, 'margin' => -10.0], $row['compliance']);

        $vessels = $this->keyed($row['vessels'], 'name');
        $this->assertEquals(18.0, $vessels['F11']['free_so2']);
        $this->assertFalse($vessels['F11']['inherited']);
        $this->assertSame(28, $vessels['F11']['reference_target']);
        $this->assertEquals(22.0, $vessels['B1']['free_so2']);
        $this->assertTrue($vessels['B1']['inherited']);
        $this->assertSame(31, $vessels['B1']['reference_target']);   // oak +3
        $this->assertSame($b1->getKey(), $vessels['B1']['id']);

        $this->assertSame(['critical' => 0, 'low' => 1, 'ok' => 0, 'high' => 0, 'no_data' => 0, 'total' => 1], $board['summary']);
        $this->assertSame(['K2S2O5'], array_column($board['products'], 'name'), 'only SO₂ products when any exist');
        unset($f11);
    }

    public function test_preview_doses_per_vessel_in_add_and_target_modes(): void
    {
        [$lot, $f11, $b1, $product] = $this->cellar();

        $add = $this->api('POST', 'cellar/so2/preview', [
            'enological_product_id' => $product->getKey(),
            'items' => [
                ['wine_lot_id' => $lot->getKey(), 'vessel_id' => $f11->getKey(), 'mg_l' => 10],
                ['wine_lot_id' => $lot->getKey(), 'vessel_id' => $b1->getKey(), 'mg_l' => 10],
            ],
        ])->assertOk()->json('data');

        $lines = $this->keyed($add['lines'], 'vessel_name');
        $this->assertSame('9.000', $lines['F11']['quantity']);   // 10 × 600 / 1000 × 1.5
        $this->assertSame('6.000', $lines['B1']['quantity']);    // 10 × 400 / 1000 × 1.5
        $this->assertSame('SO₂ +10 mg/L (18 → 28)', $lines['F11']['note']);
        $this->assertSame('SO₂ +10 mg/L (22 → 32)', $lines['B1']['note']);
        $this->assertSame(27, $lines['F11']['cost']);
        $this->assertSame('15.000', $add['totals']['quantity']);
        $this->assertSame(45, $add['totals']['cost']);
        $this->assertNull($add['totals']['short_by']);
        $this->assertSame('k2s2o5', $add['product']['method']);

        $target = $this->api('POST', 'cellar/so2/preview', [
            'enological_product_id' => $product->getKey(),
            'mode' => 'target',
            'items' => [
                ['wine_lot_id' => $lot->getKey(), 'vessel_id' => $f11->getKey(), 'mg_l' => 30],
                ['wine_lot_id' => $lot->getKey(), 'vessel_id' => $b1->getKey(), 'mg_l' => 20], // already above → dropped
            ],
        ])->assertOk()->json('data');

        $this->assertCount(1, $target['lines']);
        $this->assertEquals(12.0, $target['lines'][0]['add_mg_l']);
        $this->assertSame('10.800', $target['lines'][0]['quantity']);  // 12 × 600 / 1000 × 1.5
    }

    public function test_preview_uses_the_product_uplift_and_flags_a_stock_shortfall(): void
    {
        [$lot, $f11, , $product] = $this->cellar('ml', '0.0600', '50.000');

        $plan = $this->api('POST', 'cellar/so2/preview', [
            'enological_product_id' => $product->getKey(),
            'items' => [['wine_lot_id' => $lot->getKey(), 'vessel_id' => $f11->getKey(), 'mg_l' => 10]],
        ])->assertOk()->json('data');

        $this->assertSame('uplift', $plan['product']['method']);
        $this->assertSame('100000.000', $plan['lines'][0]['quantity']);  // 10 × 600 / 0.06
        $this->assertSame('99950.000', $plan['totals']['short_by']);
    }

    public function test_preview_refuses_a_vessel_that_does_not_hold_the_lot_and_a_liquid_without_uplift(): void
    {
        [$lot, , , $product] = $this->cellar('ml');
        $other = $this->inTenant(fn () => Vessel::create(['name' => 'EMPTY', 'type' => 'TANK', 'capacity_liters' => '100.000']));

        $this->api('POST', 'cellar/so2/preview', [
            'enological_product_id' => $product->getKey(),
            'items' => [['wine_lot_id' => $lot->getKey(), 'vessel_id' => $other->getKey(), 'mg_l' => 10]],
        ])->assertUnprocessable()->assertJsonValidationErrors(['items.0.vessel_id']);

        $this->api('POST', 'cellar/so2/preview', [
            'enological_product_id' => $product->getKey(),
            'items' => [['wine_lot_id' => $lot->getKey(), 'mg_l' => 10]],
        ])->assertUnprocessable()->assertJsonValidationErrors(['enological_product_id']);
    }

    public function test_apply_records_additions_per_vessel_and_draws_stock(): void
    {
        [$lot, $f11, $b1, $product] = $this->cellar();

        $this->api('POST', 'cellar/so2/apply', [
            'enological_product_id' => $product->getKey(),
            'note' => 'Before racking',
            'items' => [
                ['wine_lot_id' => $lot->getKey(), 'vessel_id' => $f11->getKey(), 'mg_l' => 10],
                ['wine_lot_id' => $lot->getKey(), 'vessel_id' => $b1->getKey(), 'mg_l' => 10],
            ],
        ])->assertCreated()->assertJsonPath('data.totals.quantity', '15.000');

        $this->inTenant(function () use ($f11, $product): void {
            $this->assertSame('985.000', (string) $product->refresh()->current_stock);
            $this->assertSame(2, CellarAddition::query()->count());
            $f11Addition = CellarAddition::query()->where('vessel_id', $f11->getKey())->sole();
            $this->assertSame('9.000', (string) $f11Addition->quantity);
            $this->assertSame('Before racking · SO₂ +10 mg/L (18 → 28)', $f11Addition->note);
            $this->assertSame('SO2', $f11Addition->category);
            $log = AuditLog::query()->where('action', 'cellar.so2_applied')->sole();
            $this->assertSame('15.000', $log->metadata['total'] ?? null);
        });
    }

    public function test_apply_is_all_or_nothing_when_stock_is_short(): void
    {
        [$lot, $f11, $b1, $product] = $this->cellar('g', null, '10.000');

        $this->api('POST', 'cellar/so2/apply', [
            'enological_product_id' => $product->getKey(),
            'items' => [
                ['wine_lot_id' => $lot->getKey(), 'vessel_id' => $f11->getKey(), 'mg_l' => 10],
                ['wine_lot_id' => $lot->getKey(), 'vessel_id' => $b1->getKey(), 'mg_l' => 10],
            ],
        ])->assertUnprocessable()->assertJsonValidationErrors(['quantity']);

        $this->inTenant(function () use ($product): void {
            $this->assertSame('10.000', (string) $product->refresh()->current_stock);
            $this->assertSame(0, CellarAddition::query()->count());
        });
    }

    public function test_apply_with_nothing_to_add_is_refused(): void
    {
        [$lot, $f11, , $product] = $this->cellar();

        $this->api('POST', 'cellar/so2/apply', [
            'enological_product_id' => $product->getKey(),
            'mode' => 'target',
            'items' => [['wine_lot_id' => $lot->getKey(), 'vessel_id' => $f11->getKey(), 'mg_l' => 15]],
        ])->assertUnprocessable()->assertJsonValidationErrors(['items']);
    }

    public function test_plan_for_cellar_team_creates_a_round_with_server_doses(): void
    {
        [$lot, $f11, $b1, $product] = $this->cellar();

        $this->api('POST', 'cellar/so2-rounds', [
            'enological_product_id' => $product->getKey(),
            'planned_for' => '2026-10-15',
            'items' => [
                ['wine_lot_id' => $lot->getKey(), 'vessel_id' => $f11->getKey(), 'mg_l' => 10],
                ['wine_lot_id' => $lot->getKey(), 'vessel_id' => $b1->getKey(), 'mg_l' => 10],
            ],
        ])->assertCreated()->assertJsonPath('data.count', 2);

        $this->inTenant(function () use ($f11, $product): void {
            $this->assertSame('SO₂ 14.10.2026', CellarActivityRound::query()->sole()->name);
            $a = CellarActivity::query()->where('vessel_id', $f11->getKey())->sole();
            $this->assertEquals(9, $a->payload['quantity']);
            $this->assertSame('SO2', $a->payload['category']);
            $this->assertSame('SO₂ +10 mg/L (18 → 28)', $a->payload['target_note']);
            $this->assertSame('2026-10-15', $a->planned_for->toDateString());
            $this->assertSame('1000.000', (string) $product->refresh()->current_stock, 'nothing applied yet');
            $this->assertTrue(AuditLog::query()->where('action', 'cellar.so2_round_planned')->exists());
        });
    }

    public function test_quick_entry_readings_are_audited_as_a_batch(): void
    {
        [$lot, $f11, $b1] = $this->cellar();

        $this->api('POST', 'wine-lots/analyses/bulk', ['analyses' => [
            ['wine_lot_id' => $lot->getKey(), 'vessel_id' => $f11->getKey(), 'free_so2' => 30],
            ['wine_lot_id' => $lot->getKey(), 'vessel_id' => $b1->getKey(), 'free_so2' => 26, 'total_so2' => 90],
        ]])->assertCreated();

        $this->inTenant(fn () => $this->assertSame(2, AuditLog::query()->where('action', 'cellar.bulk_analyses_recorded')->sole()->metadata['count'] ?? null));

        $vessels = $this->keyed($this->api('GET', 'cellar/so2')->json('data.lots.0.vessels'), 'name');
        $this->assertEquals(30.0, $vessels['F11']['free_so2']);
        $this->assertEquals(26.0, $vessels['B1']['free_so2']);
        $this->assertFalse($vessels['B1']['inherited']);
    }

    public function test_calculators(): void
    {
        $this->api('POST', 'cellar/calculators', ['kind' => 'so2', 'volume_liters' => 1000, 'current_free_so2' => 18, 'target_free_so2' => 28, 'ph' => 3.5])
            ->assertOk()->assertJsonPath('data.grams_k2s2o5', 15)->assertJsonPath('data.molecular_after', 0.56);

        $this->api('POST', 'cellar/calculators', ['kind' => 'so2_compliance', 'total_so2' => 160, 'wine_type' => 'RED', 'residual_sugar' => 1])
            ->assertOk()->assertJsonPath('data.compliant', false)->assertJsonPath('data.limit', 150);

        $this->api('POST', 'cellar/calculators', ['kind' => 'chaptalization', 'volume_liters' => 1000, 'current_brix' => 21])
            ->assertUnprocessable()->assertJsonValidationErrors(['target_brix']);
    }

    public function test_only_cellar_roles_reach_the_so2_screen(): void
    {
        [$lot, $f11, , $product] = $this->cellar();
        $cellar = $this->createMember($this->tenant, [TenantRole::Cellar]);
        $orders = $this->createMember($this->tenant, [TenantRole::Orders]);
        $body = ['enological_product_id' => $product->getKey(), 'items' => [['wine_lot_id' => $lot->getKey(), 'vessel_id' => $f11->getKey(), 'mg_l' => 5]]];

        $this->api('GET', 'cellar/so2', [], $cellar)->assertOk();
        $this->api('POST', 'cellar/so2/apply', $body, $cellar)->assertCreated();
        $this->api('GET', 'cellar/so2', [], $orders)->assertForbidden();
        $this->api('POST', 'cellar/so2/apply', $body, $orders)->assertForbidden();
    }
}
