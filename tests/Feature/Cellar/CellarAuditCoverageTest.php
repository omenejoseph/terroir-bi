<?php

declare(strict_types=1);

namespace Tests\Feature\Cellar;

use App\Enums\TenantRole;
use App\Models\AuditLog;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Vessel;
use App\Queries\CellarHistoryQuery;
use App\Services\Auth\ActiveTenantSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;
use Laravel\Sanctum\Sanctum;
use Symfony\Component\HttpFoundation\Response;
use Tests\Concerns\InteractsWithTenancy;
use Tests\TestCase;

/**
 * Audit coverage sweep (parity area Q): one pass through every cellar write
 * path, then every expected audit action must be present, attributed to the
 * acting member. Also the lot/vessel History feed and the Logs "Cellar" filter.
 */
class CellarAuditCoverageTest extends TestCase
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
     * @param  array<string, mixed>  $body
     * @return TestResponse<Response>
     */
    private function api(string $method, string $uri, array $body = []): TestResponse
    {
        Sanctum::actingAs($this->admin);

        $response = $this->json($method, '/api/v1/'.$uri, $body, $this->tenantHeader($this->tenant));
        $this->assertLessThan(300, $response->status(), "{$method} {$uri}: ".$response->getContent());

        return $response;
    }

    /**
     * @return list<string>
     */
    private function actions(): array
    {
        return array_values(array_unique(array_map(
            fn (AuditLog $log): string => $log->action,
            AuditLog::query()->where('tenant_id', $this->tenant->getKey())->get()->all(),
        )));
    }

    public function test_every_cellar_write_path_leaves_an_audit_entry(): void
    {
        $t1 = $this->api('POST', 'vessels', ['name' => 'T1', 'type' => 'TANK', 'capacity_liters' => 2000])->json('data.id');
        $t2 = $this->api('POST', 'vessels', ['name' => 'T2', 'type' => 'TANK', 'capacity_liters' => 2000])->json('data.id');
        $b1 = $this->api('POST', 'vessels', ['name' => 'B1', 'type' => 'BARREL', 'capacity_liters' => 225])->json('data.id');
        $this->api('PATCH', "vessels/{$b1}", ['is_faulty' => true, 'fault_note' => 'Leak']);
        $this->api('POST', 'vessels/duplicate', ['vessel_ids' => [$b1], 'count' => 1]);

        $product = $this->api('POST', 'enological-products', ['name' => 'K2S2O5', 'category' => 'SO2', 'unit' => 'g', 'current_stock' => 1000])->json('data.id');
        $this->api('POST', "enological-products/{$product}/adjust-stock", ['delta' => 10, 'note' => 'Count']);
        $template = $this->api('POST', 'fermentation-templates', ['name' => 'Red', 'additives' => [['name' => 'Yeast', 'dose' => 20, 'basis' => 'per_hL']]])->json('data.id');

        $lotA = $this->api('POST', 'wine-lots', ['name' => 'Plavac', 'grape_variety' => 'Plavac', 'vintage' => '2026', 'initial_volume' => 1000, 'vessel_id' => $t1])->json('data.id');
        $lotB = $this->api('POST', 'wine-lots', ['name' => 'Syrah', 'grape_variety' => 'Syrah', 'vintage' => '2026', 'initial_volume' => 500, 'vessel_id' => $t2])->json('data.id');
        $this->api('PATCH', "wine-lots/{$lotA}", ['notes' => 'Edited']);

        $this->api('POST', "wine-lots/{$lotA}/analyses", ['free_so2' => 18, 'ph' => 3.5, 'vessel_id' => $t1]);
        $this->api('POST', "wine-lots/{$lotA}/additions", ['name' => 'K2S2O5', 'quantity' => 5, 'unit' => 'g', 'enological_product_id' => $product, 'vessel_id' => $t1]);
        $this->api('POST', "wine-lots/{$lotA}/processes", ['kind' => 'Pump-over', 'vessel_id' => $t1]);
        $this->api('POST', "wine-lots/{$lotA}/tasting-notes", ['score' => 88]);
        $this->api('POST', "wine-lots/{$lotA}/adjust-volume", ['delta' => -10, 'reason' => 'Evaporation']);
        $this->api('POST', "wine-lots/{$lotA}/vessels", ['vessel_id' => $b1, 'volume' => 0.001]);
        $this->api('POST', "wine-lots/{$lotA}/transfers", ['type' => 'RACK', 'volume_liters' => 100, 'to_lot_id' => $lotA, 'from_vessel_id' => $t1, 'to_vessel_id' => $b1]);
        $this->api('POST', "wine-lots/{$lotA}/blend-in", ['from_vessel_id' => $t2, 'to_vessel_id' => $t1, 'volume' => 50]);
        $this->api('POST', "wine-lots/{$lotA}/protocol", ['fermentation_template_id' => $template]);
        $this->api('POST', "wine-lots/{$lotA}/protocol/plan-additions", ['planned_for' => now()->toDateString()]);
        $this->api('POST', 'wine-lots/analyses/bulk', ['analyses' => [['wine_lot_id' => $lotB, 'free_so2' => 20]]]);
        $this->api('POST', 'wine-lots/additions/bulk', ['name' => 'K2S2O5', 'unit' => 'g', 'enological_product_id' => $product, 'lots' => [['wine_lot_id' => $lotB, 'quantity' => 2]]]);
        $this->api('POST', 'cellar/so2/apply', ['enological_product_id' => $product, 'items' => [['wine_lot_id' => $lotA, 'vessel_id' => $t1, 'mg_l' => 5]]]);
        $this->api('POST', 'cellar/so2-rounds', ['enological_product_id' => $product, 'planned_for' => now()->toDateString(), 'items' => [['wine_lot_id' => $lotB, 'vessel_id' => $t2, 'mg_l' => 5]]]);

        $activity = $this->api('GET', 'cellar/activities/today')->json('data.open.0.items.0.id');
        $this->api('POST', "cellar/activities/{$activity}/complete");

        $this->api('POST', "wine-lots/{$lotB}/bottlings", ['bottle_count' => 100]);
        $bottling = $this->api('GET', "wine-lots/{$lotB}")->json('data.bottlings.0.id');
        $this->api('DELETE', "wine-lots/{$lotB}/bottlings/{$bottling}");
        $report = $this->api('POST', 'tasting-reports', ['title' => 'Panel', 'wines' => [['wine_lot_id' => $lotB, 'score' => 80]]])->json('data.id');
        $this->api('DELETE', "tasting-reports/{$report}");

        $actions = $this->actions();
        foreach ([
            'vessel.created', 'vessel.updated', 'vessel.duplicated',
            'enological_product.created', 'enological_product.stock_adjusted',
            'fermentation_template.created', 'protocol_additive.created',
            'wine_lot.created', 'wine_lot.updated', 'wine_lot_grape.created',
            'cellar_analysis.created', 'cellar_addition.created', 'cellar_process.created', 'cellar_tasting_note.created',
            'wine_lot.volume_adjusted', 'wine_lot.assigned_to_vessel', 'cellar_transfer.created', 'wine_lot.blended_in',
            'wine_lot.protocol_assigned', 'wine_lot.protocol_additions_planned', 'cellar_activity_round.planned', 'cellar_activity.created',
            'cellar.bulk_analyses_recorded', 'cellar.bulk_additions_applied', 'cellar.so2_applied', 'cellar.so2_round_planned',
            'cellar_activity.completed', 'bottling.created', 'bottling.reversed', 'tasting_report.created', 'tasting_report.deleted',
        ] as $expected) {
            $this->assertContains($expected, $actions, "missing audit action {$expected}");
        }

        $this->assertSame(0, AuditLog::query()->where('tenant_id', $this->tenant->getKey())->whereNull('actor_id')->count(), 'every entry names who did it');
    }

    public function test_history_feed_and_logs_filter(): void
    {
        $vessel = $this->api('POST', 'vessels', ['name' => 'T1', 'type' => 'TANK', 'capacity_liters' => 2000])->json('data.id');
        $lot = $this->api('POST', 'wine-lots', ['name' => 'Plavac', 'grape_variety' => 'Plavac', 'vintage' => '2026', 'initial_volume' => 1000, 'vessel_id' => $vessel])->json('data.id');
        $this->api('POST', "wine-lots/{$lot}/analyses", ['free_so2' => 18, 'vessel_id' => $vessel]);
        $this->api('POST', "wine-lots/{$lot}/adjust-volume", ['delta' => -5, 'reason' => 'Sample']);

        $history = $this->api('GET', "wine-lots/{$lot}/history")->json('data');
        $this->assertContains('wine_lot.volume_adjusted', array_column($history, 'action'));
        $this->assertContains('cellar_analysis.created', array_column($history, 'action'));
        $this->assertSame($this->admin->fullName(), $history[0]['actor_name']);

        $vesselHistory = $this->api('GET', "vessels/{$vessel}/history")->json('data');
        $this->assertContains('vessel.created', array_column($vesselHistory, 'action'));
        $this->assertContains('cellar_analysis.created', array_column($vesselHistory, 'action'));

        $this->actingAs($this->admin)
            ->withSession([ActiveTenantSession::KEY => $this->tenant->getKey()])
            ->get('/logs?area=cellar')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Logs/Index')
                ->where('logs.filters.area', 'cellar')
                ->where('logs.data', function (mixed $rows): bool {
                    $rows = $rows instanceof Collection ? $rows->all() : (array) $rows;
                    $this->assertNotEmpty($rows);
                    foreach ($rows as $r) {
                        $r = $r instanceof Collection ? $r->all() : (array) $r;
                        if (! in_array($r['subject_type'], CellarHistoryQuery::morphTypes(), true) && ! str_starts_with((string) $r['action'], 'cellar.')) {
                            return false;
                        }
                    }

                    return true;
                }));
        unset($vessel);
    }
}
