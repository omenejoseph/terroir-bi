<?php

declare(strict_types=1);

namespace Tests\Feature\Cellar;

use App\Enums\TenantRole;
use App\Models\AuditLog;
use App\Models\Bottling;
use App\Models\CellarAddition;
use App\Models\CellarAnalysis;
use App\Models\CellarProcess;
use App\Models\CellarTransfer;
use App\Models\FermentationTemplate;
use App\Models\HarvestEntry;
use App\Models\HarvestPlan;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Vessel;
use App\Models\VesselLot;
use App\Models\VineyardParcel;
use App\Models\WineLot;
use App\Models\WorkOrder;
use App\Models\WorkOrderBoard;
use App\Services\Cellar\VesselVolumeSync;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Symfony\Component\HttpFoundation\Response;
use Tests\Concerns\InteractsWithTenancy;
use Tests\TestCase;

/** Lot timeline, analytics, cellar book, traceability, protocol tasks (parity slice 1g). */
class CellarRecordsAndAnalyticsTest extends TestCase
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
     * @param  array<string, mixed>  $extra
     */
    private function lot(string $number, string $volume, ?Vessel $vessel = null, array $extra = []): WineLot
    {
        $lot = WineLot::create(array_merge([
            'lot_number' => $number, 'name' => 'Lot '.$number, 'grape_variety' => 'Plavac', 'vintage' => '2026',
            'wine_type' => 'RED', 'initial_volume' => $volume, 'current_volume' => $volume,
        ], $extra));
        if ($vessel !== null) {
            VesselLot::create(['vessel_id' => $vessel->getKey(), 'wine_lot_id' => $lot->getKey(), 'volume' => $volume]);
            app(VesselVolumeSync::class)->syncMany([$vessel->getKey()]);
        }

        return $lot;
    }

    public function test_lot_timeline_lists_events_newest_first(): void
    {
        $lot = $this->inTenant(function () {
            $v = Vessel::create(['name' => 'F11', 'type' => 'TANK', 'capacity_liters' => '1000.000']);
            $lot = $this->lot('LOT-2026-001', '500.000', $v);
            $by = $this->admin->getKey();
            Carbon::setTestNow('2026-10-14 10:00:00');
            CellarAddition::create(['wine_lot_id' => $lot->getKey(), 'vessel_id' => $v->getKey(), 'created_by_id' => $by, 'name' => 'SO2', 'quantity' => '10.000', 'unit' => 'g']);
            CellarAnalysis::create(['wine_lot_id' => $lot->getKey(), 'created_by_id' => $by, 'date' => '2026-10-14 11:00:00', 'free_so2' => 30, 'ph' => 3.4]);
            CellarProcess::create(['wine_lot_id' => $lot->getKey(), 'created_by_id' => $by, 'date' => '2026-10-14 12:00:00', 'kind' => 'Loss', 'volume' => '5.000', 'note' => 'Evaporation']);

            return $lot;
        });

        $all = $this->api('GET', "wine-lots/{$lot->getKey()}/timeline")->assertOk()->json('data');
        $this->assertContains('created', array_column($all, 'type'));
        $this->assertContains('vessel', array_column($all, 'type'));
        // Vessel placement is stamped by the database clock, so leave it out of the order check.
        $events = array_values(array_filter($all, fn (array $e): bool => ! in_array($e['type'], ['vessel', 'created'], true)));

        $this->assertSame(['process', 'analysis', 'addition'], array_column($events, 'type'));
        $this->assertSame('Loss 5 L', $events[0]['title']);
        $this->assertSame('free SO₂ 30 · pH 3.4', $events[1]['detail']);
        $this->assertSame('SO2 10 g → F11', $events[2]['title']);
    }

    public function test_analytics_kpis_timeline_health_and_cost_gating(): void
    {
        $cellar = $this->createMember($this->tenant, [TenantRole::Cellar]);
        $this->inTenant(function () {
            $full = Vessel::create(['name' => 'F1', 'type' => 'TANK', 'capacity_liters' => '1000.000']);
            Vessel::create(['name' => 'EMPTY', 'type' => 'TANK', 'capacity_liters' => '1000.000']);
            $ready = $this->lot('LOT-2026-001', '750.000', $full, ['status' => 'READY', 'grape_cost' => 75000]);
            $old = $this->lot('LOT-2026-002', '250.000', null, ['status' => 'AGING']);
            $by = $this->admin->getKey();
            CellarAnalysis::create(['wine_lot_id' => $ready->getKey(), 'created_by_id' => $by, 'date' => now()->subDays(3), 'free_so2' => 15, 'volatile_acidity' => 0.8]);
            CellarAnalysis::create(['wine_lot_id' => $old->getKey(), 'created_by_id' => $by, 'date' => now()->subDays(45), 'free_so2' => 30]);
            CellarAddition::create(['wine_lot_id' => $ready->getKey(), 'created_by_id' => $by, 'name' => 'K2S2O5', 'category' => 'SO2', 'quantity' => '5.000', 'unit' => 'g']);
            Bottling::create(['wine_lot_id' => $ready->getKey(), 'created_by_id' => $by, 'bottle_count' => 100, 'bottle_volume_ml' => 750, 'volume_used' => '75.000', 'date' => now()->subMonth()]);
            WorkOrder::create(['title' => 'Rack F1', 'category' => 'CELLAR', 'due_date' => now()->subDay(), 'created_by_id' => $by]);
        });

        $data = $this->api('GET', 'cellar/analytics')->assertOk()->json('data');
        $k = $data['kpis'];
        $this->assertEquals(1000.0, $k['wine_in_cellar']);
        $this->assertEquals(750.0, $k['ready_to_bottle']);
        $this->assertSame(1333, $k['estimated_bottles']);
        $this->assertSame(75, $k['average_cost_per_liter']);      // (100/L × 750 L + 0 × 250 L) / 1000 L
        $this->assertSame(1, $k['overdue_tasks']);
        $this->assertSame(1, $k['needs_so2']);                    // the AGING lot has no SO₂ addition
        $this->assertSame(1, $k['needs_analysis']);               // 45 days old
        $this->assertEquals(37.5, $k['vessel_utilisation']);
        $this->assertSame(1, $k['empty_vessels']);

        $this->assertCount(12, $data['timeline']);
        $this->assertEquals(75.0, $data['timeline'][10]['out']);   // September
        $this->assertEquals(1000.0, $data['timeline'][11]['in']);  // lots created this month

        $health = array_column($data['health'], null, 'lot_number');
        $this->assertSame('red', $health['LOT-2026-001']['level']);
        $this->assertSame(['va_high', 'so2_low'], $health['LOT-2026-001']['flags']);
        $this->assertSame('amber', $health['LOT-2026-002']['level']);
        $this->assertIsArray($data['alerts']);

        $this->assertNull($this->api('GET', 'cellar/analytics', [], $cellar)->json('data.kpis.average_cost_per_liter'), 'no finance.view, no cost');
    }

    public function test_cellar_book_covers_every_lot_and_exports_quoted_csv(): void
    {
        $this->inTenant(function () {
            $v = Vessel::create(['name' => 'F11', 'type' => 'TANK', 'capacity_liters' => '1000.000']);
            $blend = $this->lot('BLEND-261014', '200.000', $v, ['name' => 'Cuvée, "Reserve"']);
            CellarProcess::create(['wine_lot_id' => $blend->getKey(), 'created_by_id' => $this->admin->getKey(), 'date' => now(), 'kind' => 'Loss', 'volume' => '2.000', 'note' => 'Leak, small']);
        });

        $rows = $this->api('GET', 'cellar/book?from=2026-10-01&to=2026-10-31')->assertOk()->json('data');
        $this->assertSame(['Blend created', 'Loss'], array_column($rows, 'event'));
        $this->assertSame($this->admin->fullName(), $rows[1]['by']);

        $csv = $this->api('GET', 'cellar/book?from=2026-10-01&to=2026-10-31&format=csv')->assertOk()->streamedContent();
        $this->assertStringContainsString('"Cuvée, ""Reserve"""', $csv);
        $this->assertStringContainsString('"Leak, small"', $csv);
    }

    public function test_traceability_follows_blends_back_to_the_parcel(): void
    {
        [$blend] = $this->inTenant(function () {
            $parcel = VineyardParcel::create(['name' => 'Gornji vrh', 'grape_variety' => 'Plavac']);
            $plan = HarvestPlan::create(['name' => 'Harvest 2026', 'season' => '2026', 'created_by_id' => $this->admin->getKey()]);
            $source = $this->lot('LOT-2026-001', '500.000');
            HarvestEntry::create(['harvest_plan_id' => $plan->getKey(), 'parcel_id' => $parcel->getKey(), 'wine_lot_id' => $source->getKey(), 'grape_variety' => 'Plavac', 'actual_yield_kg' => '800.000', 'actual_date' => '2026-09-20']);
            $v = Vessel::create(['name' => 'B1', 'type' => 'BARREL', 'capacity_liters' => '500.000']);
            $blend = $this->lot('BLEND-261014', '200.000', $v);
            CellarTransfer::create(['from_lot_id' => $source->getKey(), 'to_lot_id' => $blend->getKey(), 'to_vessel_id' => $v->getKey(), 'created_by_id' => $this->admin->getKey(), 'type' => 'BLEND', 'volume_liters' => '200.000']);

            return [$blend];
        });

        $trace = $this->api('GET', "wine-lots/{$blend->getKey()}/trace")->assertOk()->json('data');

        $this->assertSame('BLEND-261014', $trace['lot_number']);
        $this->assertSame([['id' => $trace['vessels'][0]['id'], 'name' => 'B1']], $trace['vessels']);
        $this->assertSame('200.000', $trace['blended_from'][0]['volume']);
        $this->assertSame('Gornji vrh', $trace['blended_from'][0]['lot']['harvest'][0]['parcel']['name']);
        $this->assertSame('800.000', $trace['blended_from'][0]['lot']['harvest'][0]['kg']);
    }

    public function test_protocol_tasks_land_on_the_default_board_and_are_audited(): void
    {
        [$lot, $board] = $this->inTenant(function () {
            $board = WorkOrderBoard::create(['name' => 'Team', 'created_by_id' => $this->admin->getKey(), 'is_default' => true]);
            $template = FermentationTemplate::create(['name' => 'Red', 'stages' => [['name' => 'Cold soak', 'dayStart' => 0, 'dayEnd' => 5, 'actions' => [['type' => 'PUMPOVER', 'description' => 'Pump over']]]]]);
            $lot = $this->lot('LOT-2026-001', '500.000');

            return [$lot, $board, $template];
        });
        $templateId = $this->inTenant(fn () => FermentationTemplate::query()->value('id'));

        $this->api('POST', "wine-lots/{$lot->getKey()}/protocol", ['fermentation_template_id' => $templateId])->assertOk();
        $this->api('POST', "wine-lots/{$lot->getKey()}/protocol/generate")->assertOk()->assertJsonPath('data.created', 1);

        $this->inTenant(function () use ($board): void {
            $this->assertSame($board->getKey(), WorkOrder::query()->sole()->board_id);
            $this->assertTrue(AuditLog::query()->where('action', 'wine_lot.protocol_assigned')->exists());
            $this->assertTrue(AuditLog::query()->where('action', 'wine_lot.protocol_tasks_generated')->exists());
        });
    }
}
