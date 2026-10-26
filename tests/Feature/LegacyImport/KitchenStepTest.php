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

/** The kitchen comes across with its stock as it stands; unknown codes are mapped and reported. */
class KitchenStepTest extends TestCase
{
    use InteractsWithTenancy;
    use LegacyFixture;
    use RefreshDatabase;

    private const STEPS = ['users', 'kitchen'];

    public function test_items_movements_stock_takes_and_prep_tickets_come_across(): void
    {
        $this->createLegacySchema();
        $this->legacyInsert('User', ['id' => 'u1', 'name' => 'Marko Kuhar', 'email' => 'marko@bibich.co', 'hashedPassword' => '$2b$12$x', 'role' => 'KITCHEN', 'createdAt' => '2026-01-01', 'canEditOrders' => 'f', 'canSeeShippedOrders' => 'f']);
        $this->legacyInsert('KitchenItem', ['id' => 'k1', 'name' => 'Flour', 'category' => 'RAW_MATERIAL', 'unit' => 'kg', 'currentStock' => '12.5', 'minStock' => '5', 'costPerUnit' => '0.89', 'location' => 'restaurant', 'isActive' => 't', 'sortOrder' => 1, 'createdAt' => '2026-01-01', 'updatedAt' => '2026-01-01']);
        // A unit the rebuild does not know: mapped to pieces and reported.
        $this->legacyInsert('KitchenItem', ['id' => 'k2', 'name' => 'Eggs', 'category' => 'RAW_MATERIAL', 'unit' => 'kom', 'currentStock' => '30', 'location' => 'tasting_room', 'isActive' => 't', 'createdAt' => '2026-01-01', 'updatedAt' => '2026-01-01']);
        $this->legacyInsert('KitchenMovement', ['id' => 'm1', 'itemId' => 'k1', 'type' => 'OUT', 'quantity' => '-2.5', 'note' => 'Bread', 'userId' => 'u1', 'createdAt' => '2026-02-01']);
        $this->legacyInsert('KitchenCheck', ['id' => 'c1', 'date' => '2026-03-01', 'status' => 'COMPLETED', 'userId' => 'u1', 'createdAt' => '2026-03-01', 'updatedAt' => '2026-03-01']);
        $this->legacyInsert('KitchenCheckItem', ['id' => 'ci1', 'checkId' => 'c1', 'itemId' => 'k1', 'systemStock' => '15', 'physicalCount' => '12.5', 'difference' => '-2.5']);
        $this->legacyInsert('KitchenWorkOrder', ['id' => 'w1', 'title' => 'Lunch', 'date' => '2026-03-02', 'status' => 'COMPLETED', 'assignedTo' => 'Marko', 'userId' => 'u1', 'createdAt' => '2026-03-01', 'updatedAt' => '2026-03-02']);
        $this->legacyInsert('KitchenWorkOrderItem', ['id' => 'wi1', 'workOrderId' => 'w1', 'itemId' => 'k1', 'quantity' => '2', 'sortOrder' => 0]);
        $this->legacyInsert('KitchenWorkOrderItem', ['id' => 'wi2', 'workOrderId' => 'w1', 'customName' => 'Staff meal', 'quantity' => '6', 'unit' => 'portions', 'sortOrder' => 1]);

        $tenant = $this->createTenant();
        $ctx = new ImportContext($tenant, DB::connection('legacy'), new IdMap($tenant->getKey()), new Report);
        app(LegacyImporter::class)->run($ctx, self::STEPS);

        $flour = DB::table('kitchen_items')->where('name', 'Flour')->first();
        $this->assertNotNull($flour);
        $this->assertSame('12.500', number_format((float) $flour->current_stock, 3, '.', ''));
        $this->assertSame(89, (int) $flour->cost_per_unit);
        $this->assertSame('pcs', DB::table('kitchen_items')->where('name', 'Eggs')->value('unit'));
        $this->assertNotEmpty(array_filter($ctx->report->warnings(), fn (string $w): bool => str_contains($w, "'kom' not recognised")));

        $this->assertSame(1, DB::table('kitchen_movements')->count());
        $this->assertSame(1, DB::table('kitchen_check_items')->count());
        $this->assertSame(2, DB::table('kitchen_work_order_items')->count());
        $this->assertSame('COMPLETED', DB::table('kitchen_work_orders')->value('status'));
    }
}
