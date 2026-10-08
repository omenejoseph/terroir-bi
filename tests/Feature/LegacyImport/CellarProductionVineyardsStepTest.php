<?php

declare(strict_types=1);

namespace Tests\Feature\LegacyImport;

use App\Services\LegacyImport\IdMap;
use App\Services\LegacyImport\ImportContext;
use App\Services\LegacyImport\LegacyImporter;
use App\Services\LegacyImport\Report;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\InteractsWithTenancy;
use Tests\Concerns\LegacyFixture;
use Tests\TestCase;

class CellarProductionVineyardsStepTest extends TestCase
{
    use InteractsWithTenancy;
    use LegacyFixture;
    use RefreshDatabase;

    private const STEPS = ['users', 'pricing_tiers', 'inventory', 'suppliers', 'cellar', 'production', 'vineyards'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->createLegacySchema();

        $this->legacyInsert('User', ['id' => 'u1', 'name' => 'Filip Bibic', 'email' => 'filip@bibich.co', 'hashedPassword' => '$2b$12$x', 'role' => 'ADMIN', 'createdAt' => '2026-01-01', 'canEditOrders' => 't', 'canSeeShippedOrders' => 't']);
        $this->legacyInsert('InventoryItem', ['id' => 'i1', 'name' => 'R3 2025', 'sku' => 'R3', 'category' => 'FINISHED', 'unit' => 'bottles', 'currentStock' => '10', 'isActive' => 't', 'bottlesPerCase' => 12, 'isForSale' => 't', 'sortOrder' => 0, 'packSize' => 1, 'hideFromPortal' => 'f', 'isAutoCreated' => 'f', 'createdAt' => '2026-01-01', 'updatedAt' => '2026-01-01']);
        $this->legacyInsert('Supplier', ['id' => 's1', 'companyName' => 'Coop', 'isActive' => 't', 'excludeFromStats' => 'f', 'isCooperant' => 't', 'portalEnabled' => 'f', 'createdAt' => '2026-01-01', 'updatedAt' => '2026-01-01']);
    }

    private function import(): ImportContext
    {
        $tenant = $this->createTenant();
        $ctx = new ImportContext($tenant, DB::connection('legacy'), new IdMap($tenant->getKey()), new Report);
        app(LegacyImporter::class)->run($ctx, self::STEPS);

        return $ctx;
    }

    private function warned(ImportContext $ctx, string $needle): bool
    {
        return array_filter($ctx->report->warnings(), fn ($w) => str_contains($w, $needle)) !== [];
    }

    /** @param array<string, mixed> $o */
    private function vessel(string $id, string $name, array $o = []): void
    {
        $this->legacyInsert('Vessel', $o + ['id' => $id, 'name' => $name, 'type' => 'TANK', 'capacityLiters' => 10500, 'currentVolume' => 0, 'status' => 'AVAILABLE', 'isActive' => 't', 'isFaulty' => 'f', 'room' => 'Main cellar - down', 'createdAt' => '2026-02-27', 'updatedAt' => '2026-02-27']);
    }

    /** @param array<string, mixed> $o */
    private function lot(string $id, string $number, array $o = []): void
    {
        $this->legacyInsert('WineLot', $o + ['id' => $id, 'lotNumber' => $number, 'name' => "Lot {$number}", 'grapeVariety' => 'Crno', 'vintage' => '2025', 'initialVolume' => 10500, 'currentVolume' => 10500, 'status' => 'AGING', 'wineType' => 'RED', 'createdAt' => '2026-02-27', 'updatedAt' => '2026-02-27']);
    }

    public function test_vessels_lots_contents_and_volume_warnings(): void
    {
        $this->vessel('v1', 'ST01', ['currentVolume' => 10500, 'positionX' => 194, 'isFaulty' => 't', 'faultNote' => 'Leaks']);
        $this->vessel('v2', 'ST01', ['type' => 'BARREL', 'capacityLiters' => 225, 'currentVolume' => 300]); // duplicate name, over capacity
        $this->vessel('v3', 'X', ['type' => 'SILO']);
        $this->vessel('v4', 'Y', ['room' => null]);
        $this->legacyInsert('FermentationTemplate', ['id' => 'ft1', 'name' => 'Red', 'mlf' => 't', 'isActive' => 't', 'stages' => 'not json', 'targetTempMin' => '24.4999', 'createdAt' => '2026-01-01', 'updatedAt' => '2026-01-01']);
        $this->lot('l1', 'LOT-2026-002', ['fermentationTemplateId' => 'ft1', 'grapeCost' => '1872.10', 'grapePricePerKg' => '0.85', 'harvestWeightKg' => '1234.5678']);
        $this->lot('l2', 'LOT-2026-003', ['status' => 'WEIRD']);
        $this->lot('l3', 'LOT-2026-004', ['wineType' => 'GREEN']);
        $this->legacyInsert('WineLotGrape', ['id' => 'g1', 'wineLotId' => 'l1', 'grapeVariety' => 'Syrah', 'percentage' => '60.004', 'pricePerKg' => '0.9', 'weightKg' => '700', 'harvestEntryId' => 'he1', 'createdAt' => '2026-02-27']);
        $this->legacyInsert('VesselLot', ['id' => 'vl1', 'volume' => '10000', 'addedAt' => '2026-02-27', 'vesselId' => 'v1', 'wineLotId' => 'l1']);
        $this->legacyInsert('VesselLot', ['id' => 'vl2', 'volume' => '5', 'addedAt' => '2026-02-27', 'vesselId' => 'nope', 'wineLotId' => 'l1']);

        $ctx = $this->import();

        $this->assertSame(['ST01', 'ST01', 'Y'], DB::table('vessels')->orderBy('name')->pluck('name')->all());
        $this->assertSame(1, $ctx->report->counts()['vessels']['skipped']); // SILO
        $v1 = DB::table('vessels')->where('position_x', 194)->sole();
        $this->assertTrue((bool) $v1->is_faulty);
        $this->assertSame('Leaks', $v1->fault_note);
        $this->assertSame('Main Cellar', DB::table('vessels')->where('name', 'Y')->value('room'));

        $this->assertSame(2, DB::table('wine_lots')->count()); // WEIRD status skipped
        $l1 = DB::table('wine_lots')->where('lot_number', 'LOT-2026-002')->sole();
        $this->assertSame([187210, 85], [(int) $l1->grape_cost, (int) $l1->grape_price_per_kg]);
        $this->assertEqualsWithDelta(1234.568, (float) $l1->harvest_weight_kg, 0.0001);
        $this->assertNotNull($l1->fermentation_template_id);
        $this->assertNull(DB::table('wine_lots')->where('lot_number', 'LOT-2026-004')->value('wine_type'));

        $this->assertEqualsWithDelta(24.5, (float) DB::table('fermentation_templates')->value('target_temp_min'), 0.0001);
        $this->assertNull(DB::table('fermentation_templates')->value('stages'));

        $this->assertSame(1, DB::table('wine_lot_grapes')->count());
        $this->assertNull(DB::table('wine_lot_grapes')->value('harvest_entry_id'));
        $this->assertSame(1, DB::table('vessel_lots')->count());

        $this->assertTrue($this->warned($ctx, 'stages is not valid JSON'));
        $this->assertTrue($this->warned($ctx, 'vessels over capacity (legacy data kept): ST01'));
        $this->assertTrue($this->warned($ctx, "unknown wine type 'GREEN'"));
        $this->assertTrue($this->warned($ctx, 'harvest entries are not migrated'));
        $this->assertTrue($this->warned($ctx, '2 vessels have current_volume ≠ sum of their vessel_lots'));
    }

    public function test_cellar_activity_rows(): void
    {
        $this->vessel('v1', 'ST01');
        $this->lot('l1', 'LOT-1');
        $this->lot('l2', 'LOT-2');
        $this->legacyInsert('EnologicalProduct', ['id' => 'p1', 'name' => 'EB Berry Mix', 'category' => 'OTHER', 'unit' => 'g', 'currentStock' => '12.34567', 'isActive' => 't', 'createdAt' => '2026-01-01', 'updatedAt' => '2026-01-01', 'supplierId' => 's1', 'so2UpliftPerUnit' => '0.12345', 'costPerUnit' => '0.2']);
        $this->legacyInsert('CellarAnalysis', ['id' => 'a1', 'date' => '2026-03-01', 'pH' => '3.4567', 'totalAcidity' => '5.55', 'volatileAcidity' => '0.4567', 'alcohol' => '13.456', 'freeSO2' => '28.5', 'density' => '0.99421', 'createdAt' => '2026-03-01', 'wineLotId' => 'l1', 'createdById' => 'ghost', 'vesselId' => 'v1']);
        $this->legacyInsert('CellarAddition', ['id' => 'ad1', 'name' => 'Berry Mix', 'quantity' => '11.25', 'unit' => 'mL', 'costPerUnit' => '0.0125', 'totalCost' => '0.14', 'createdAt' => '2026-03-01', 'wineLotId' => 'l1', 'createdById' => 'u1', 'enologicalProductId' => 'p1']);
        $this->legacyInsert('CellarProcess', ['id' => 'pr1', 'date' => '2026-03-02', 'kind' => 'Filtration', 'volume' => '500', 'createdAt' => '2026-03-02', 'wineLotId' => 'l1', 'createdById' => 'u1']);
        $this->legacyInsert('CellarTransfer', ['id' => 't1', 'type' => 'BLEND', 'volumeLiters' => '1000.5', 'createdAt' => '2026-03-03', 'fromLotId' => 'l1', 'toLotId' => 'l2', 'fromVesselId' => 'v1', 'createdById' => 'u1']);
        $this->legacyInsert('CellarTransfer', ['id' => 't2', 'type' => 'POUR', 'volumeLiters' => '1', 'createdAt' => '2026-03-03', 'fromLotId' => 'l1', 'toLotId' => 'l2', 'createdById' => 'u1']);
        $this->legacyInsert('CellarTransfer', ['id' => 't3', 'type' => 'RACK', 'volumeLiters' => '1', 'createdAt' => '2026-03-03', 'fromLotId' => 'l1', 'toLotId' => 'gone', 'createdById' => 'u1']);
        $this->legacyInsert('TastingReport', ['id' => 'tr1', 'title' => 'Spring', 'date' => '2026-04-01', 'createdById' => 'u1', 'createdAt' => '2026-04-01']);
        $this->legacyInsert('CellarTastingNote', ['id' => 'tn1', 'date' => '2026-04-01', 'overall' => str_repeat('y', 300), 'score' => '91', 'createdAt' => '2026-04-01', 'wineLotId' => 'l1', 'createdById' => 'u1', 'tastingReportId' => 'tr1']);

        $ctx = $this->import();

        $p = DB::table('enological_products')->sole();
        $this->assertEqualsWithDelta(12.346, (float) $p->current_stock, 0.0001);
        $this->assertEqualsWithDelta(0.1235, (float) $p->so2_uplift_per_unit, 0.00001);
        $this->assertSame(20, (int) $p->cost_per_unit);
        $this->assertNotNull($p->supplier_id);

        $a = DB::table('cellar_analyses')->sole();
        $this->assertEqualsWithDelta(3.46, (float) $a->ph, 0.0001);
        $this->assertEqualsWithDelta(0.457, (float) $a->volatile_acidity, 0.0001);
        $this->assertEqualsWithDelta(13.46, (float) $a->alcohol, 0.0001);
        $this->assertEqualsWithDelta(0.9942, (float) $a->density, 0.00001);
        $this->assertNotNull($a->vessel_id);
        $this->assertSame(DB::table('users')->where('email', 'legacy-import@bibich.invalid')->value('id'), $a->created_by_id);

        $this->assertSame(['mL', 1], [DB::table('cellar_additions')->value('unit'), (int) DB::table('cellar_additions')->value('cost_per_unit')]);
        $this->assertSame(14, (int) DB::table('cellar_additions')->value('total_cost'));
        $this->assertNotNull(DB::table('cellar_additions')->value('enological_product_id'));
        $this->assertSame('Filtration', DB::table('cellar_processes')->value('kind'));

        $this->assertSame(1, DB::table('cellar_transfers')->count());
        $this->assertSame(2, $ctx->report->counts()['cellar_transfers']['skipped']);
        $this->assertNotNull(DB::table('cellar_transfers')->value('from_vessel_id'));
        $this->assertNull(DB::table('cellar_transfers')->value('to_vessel_id'));

        $this->assertSame(1, DB::table('tasting_reports')->count());
        $this->assertSame(255, mb_strlen((string) DB::table('cellar_tasting_notes')->value('overall')));
        $this->assertSame(91, (int) DB::table('cellar_tasting_notes')->value('score'));
        $this->assertNotNull(DB::table('cellar_tasting_notes')->value('tasting_report_id'));
    }

    public function test_work_orders_boards_and_production_plans(): void
    {
        $this->vessel('v1', 'ST01');
        $this->lot('l1', 'LOT-1');
        $this->legacyInsert('Board', ['id' => 'b1', 'name' => 'Team Board', 'ownerId' => 'u1', 'sortOrder' => 1, 'createdAt' => '2026-01-01', 'updatedAt' => '2026-01-01', 'isDefault' => 't']);
        $wo = ['category' => 'CELLAR', 'priority' => 'MEDIUM', 'status' => 'DONE', 'createdById' => 'u1', 'createdAt' => '2026-03-01', 'updatedAt' => '2026-03-01', 'sortOrder' => 0, 'listOrder' => 0];
        $this->legacyInsert('WorkOrder', $wo + ['id' => 'w1', 'title' => 'Bottle', 'boardId' => 'b1', 'wineLotId' => 'l1', 'vesselId' => 'v1', 'assigneeId' => 'u1', 'completedAt' => '2026-03-02']);
        $this->legacyInsert('WorkOrder', array_merge($wo, ['id' => 'w2', 'title' => 'Dropped', 'status' => 'CANCELLED']));
        $this->legacyInsert('WorkOrder', array_merge($wo, ['id' => 'w3', 'title' => 'Odd', 'category' => 'MYSTERY', 'priority' => 'URGENT', 'assigneeId' => 'ghost', 'status' => 'TODO']));
        $this->legacyInsert('ProductionPlan', ['id' => 'pp1', 'name' => 'Spring 2026', 'status' => 'DRAFT', 'createdAt' => '2026-03-01', 'updatedAt' => '2026-03-01', 'createdById' => 'u1']);
        $this->legacyInsert('ProductionPlanRow', ['id' => 'r1', 'planId' => 'pp1', 'baseItemId' => 'i1', 'newVintage' => '2026', 'quantity' => '1200', 'planUnit' => 'liters', 'sortOrder' => 0]);
        $this->legacyInsert('ProductionPlanRow', ['id' => 'r2', 'planId' => 'pp1', 'baseItemId' => 'gone', 'quantity' => '1', 'planUnit' => 'liters', 'sortOrder' => 1]);
        $this->legacyInsert('ProductionPlanRow', ['id' => 'r3', 'planId' => 'pp1', 'baseItemId' => 'i1', 'quantity' => '1', 'planUnit' => 'pallets', 'sortOrder' => 2]);

        $ctx = $this->import();

        $this->assertSame(1, DB::table('work_order_boards')->count());
        $this->assertSame(['Bottle', 'Dropped', 'Odd'], DB::table('work_orders')->orderBy('title')->pluck('title')->all());
        $dropped = DB::table('work_orders')->where('title', 'Dropped')->sole();
        $this->assertSame('CANCELLED', $dropped->status);
        $this->assertNull($dropped->completed_at);
        $w1 = DB::table('work_orders')->where('title', 'Bottle')->sole();
        $this->assertNotNull($w1->board_id);
        $this->assertNotNull($w1->wine_lot_id);
        $this->assertNotNull($w1->vessel_id);
        $this->assertNotNull($w1->assignee_id);
        $this->assertNotNull($w1->completed_at);

        $w3 = DB::table('work_orders')->where('title', 'Odd')->sole();
        $this->assertSame(['OTHER', 'MEDIUM'], [$w3->category, $w3->priority]);
        $this->assertNull($w3->assignee_id);

        $this->assertSame(1, DB::table('production_plans')->count());
        $this->assertSame(1, DB::table('production_plan_rows')->count());
        $this->assertSame(2, $ctx->report->counts()['production_plan_rows']['skipped']);
    }

    public function test_vineyards(): void
    {
        $this->legacyInsert('Supplier', ['id' => 's2', 'companyName' => 'Grower', 'isActive' => 't', 'excludeFromStats' => 'f', 'isCooperant' => 't', 'portalEnabled' => 'f', 'createdAt' => '2026-01-01', 'updatedAt' => '2026-01-01']);
        $p = ['isActive' => 't', 'createdAt' => '2026-01-01', 'updatedAt' => '2026-01-01', 'grapeVariety' => 'Plavac'];
        $this->legacyInsert('VineyardParcel', $p + ['id' => 'p1', 'name' => 'Ravni Kotari', 'ownership' => 'OWN', 'areaHectares' => '1.23456', 'latitude' => '44.1234567', 'geoPolygon' => '[[44.1,15.2],[44.2,15.3]]', 'weatherStationId' => 'ws1']);
        $this->legacyInsert('VineyardParcel', $p + ['id' => 'p2', 'name' => 'Coop plot', 'ownership' => 'COOPERANT', 'cooperantSupplierId' => 's2', 'geoPolygon' => '{broken']);
        $this->legacyInsert('VineyardParcel', $p + ['id' => 'p3', 'name' => 'Odd', 'ownership' => 'LEASED']);
        $this->legacyInsert('PhenologyLog', ['id' => 'ph1', 'date' => '2026-05-01', 'stage' => 'FRUIT_SET', 'progress' => 40, 'photoUrl' => 'https://blob.example/x.jpg', 'createdAt' => '2026-05-01', 'parcelId' => 'p1']);
        $this->legacyInsert('PhenologyLog', ['id' => 'ph2', 'date' => '2026-05-01', 'stage' => 'DORMANT', 'createdAt' => '2026-05-01', 'parcelId' => 'p1']);
        $this->legacyInsert('CropEstimate', ['id' => 'ce1', 'date' => '2026-08-01', 'season' => '2026', 'estimatedYieldKg' => '10500', 'createdAt' => '2026-08-01', 'parcelId' => 'p1']);
        $this->legacyInsert('CropEstimate', ['id' => 'ce2', 'date' => '2026-08-01', 'season' => '2026', 'clusterCount' => 12, 'avgClusterWeight' => '0.2', 'sampleVineCount' => 5, 'estimatedYieldKg' => '300', 'createdAt' => '2026-08-01', 'parcelId' => 'p1']);
        $this->legacyInsert('VineyardApplication', ['id' => 'va1', 'date' => '2026-06-01', 'type' => 'SPRAY', 'product' => 'Copper', 'dosage' => '11.250', 'dosageUnit' => 'mL', 'phiDays' => 14, 'phiEndDate' => '2026-06-15 00:00:00', 'createdAt' => '2026-06-01', 'parcelId' => 'p1']);
        $this->legacyInsert('GrapeContract', ['id' => 'gc1', 'season' => '2026', 'status' => 'FULFILLED', 'grapeVariety' => 'Plavac', 'estimatedKg' => '5000', 'deliveredKg' => '5100', 'pricePerKg' => '0.85', 'minBrix' => '21.555', 'maxPH' => '3.6', 'createdAt' => '2026-01-01', 'updatedAt' => '2026-01-01', 'supplierId' => 's2', 'parcelId' => 'p2']);
        $this->legacyInsert('GrapeContract', ['id' => 'gc2', 'season' => '2026', 'status' => 'ACTIVE', 'grapeVariety' => 'Plavac', 'estimatedKg' => '1', 'deliveredKg' => '0', 'pricePerKg' => '1', 'createdAt' => '2026-01-01', 'updatedAt' => '2026-01-01', 'supplierId' => 'nobody']);
        $this->legacyInsert('IntakeBooking', ['id' => 'ib1', 'date' => '2026-09-01', 'status' => 'COMPLETED', 'estimatedKg' => '800', 'createdAt' => '2026-09-01', 'updatedAt' => '2026-09-01', 'supplierId' => 's2']);

        $ctx = $this->import();

        $this->assertSame(2, DB::table('vineyard_parcels')->count());
        $p1 = DB::table('vineyard_parcels')->where('name', 'Ravni Kotari')->sole();
        $this->assertEqualsWithDelta(1.2346, (float) $p1->area_hectares, 0.00001);
        $this->assertSame([[44.1, 15.2], [44.2, 15.3]], json_decode((string) $p1->geo_polygon, true));
        $p2 = DB::table('vineyard_parcels')->where('name', 'Coop plot')->sole();
        $this->assertNull($p2->geo_polygon);
        $this->assertNotNull($p2->cooperant_supplier_id);
        $this->assertTrue($this->warned($ctx, 'geo polygon is not valid JSON'));
        $this->assertTrue($this->warned($ctx, '1 parcels had a weather station id'));

        $this->assertSame(1, DB::table('phenology_logs')->count());
        $this->assertEqualsWithDelta(40.0, (float) DB::table('phenology_logs')->value('progress_percent'), 0.001);

        $this->assertSame(2, DB::table('crop_estimates')->count());
        $zero = DB::table('crop_estimates')->where('estimated_yield_kg', 10500)->sole();
        $this->assertSame([0, 0], [(int) $zero->cluster_count, (int) $zero->sample_vine_count]);
        $this->assertTrue($this->warned($ctx, '1 crop estimates had no cluster/sample inputs'));

        $this->assertSame('11.25 mL', DB::table('vineyard_applications')->value('dosage'));
        $this->assertSame('2026-06-15', substr((string) DB::table('vineyard_applications')->value('phi_end_date'), 0, 10));

        $this->assertSame(1, DB::table('grape_contracts')->count());
        $this->assertSame(85, (int) DB::table('grape_contracts')->value('price_per_kg'));
        $this->assertSame('PROCESSED', DB::table('intake_bookings')->value('status'));
    }

    public function test_not_migrated_scan_lists_populated_unmapped_tables(): void
    {
        $this->legacyInsert('Reservation', ['id' => 'r1', 'guestName' => 'X']);

        $ctx = $this->import();

        $this->assertArrayNotHasKey('Reservation', $ctx->report->notMigrated(), 'a partial --only run does not scan');

        $tenant = $this->createTenant();
        $full = new ImportContext($tenant, DB::connection('legacy'), new IdMap($tenant->getKey()), new Report);
        app(LegacyImporter::class)->run($full);

        $this->assertSame(1, $full->report->notMigrated()['Reservation']['rows']);
        $this->assertStringContainsString('hospitality', $full->report->notMigrated()['Reservation']['reason']);
        $this->assertArrayNotHasKey('Vessel', $full->report->notMigrated());
    }
}
