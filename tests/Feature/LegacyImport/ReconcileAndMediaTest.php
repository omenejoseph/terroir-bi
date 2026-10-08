<?php

declare(strict_types=1);

namespace Tests\Feature\LegacyImport;

use App\Models\Tenant;
use App\Services\LegacyImport\IdMap;
use App\Services\LegacyImport\ImportContext;
use App\Services\LegacyImport\LegacyImporter;
use App\Services\LegacyImport\MediaCopier;
use App\Services\LegacyImport\Reconciler;
use App\Services\LegacyImport\Report;
use App\Services\Uploads\Contracts\ObjectStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\InteractsWithTenancy;
use Tests\Concerns\LegacyFixture;
use Tests\Support\FakeObjectStore;
use Tests\TestCase;

class ReconcileAndMediaTest extends TestCase
{
    use InteractsWithTenancy;
    use LegacyFixture;
    use RefreshDatabase;

    private FakeObjectStore $store;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createLegacySchema();
        $this->store = new FakeObjectStore;
        $this->app->instance(ObjectStore::class, $this->store);

        $item = ['category' => 'FINISHED', 'unit' => 'bottles', 'isActive' => 't', 'bottlesPerCase' => 12, 'isForSale' => 't', 'sortOrder' => 0, 'packSize' => 1, 'hideFromPortal' => 'f', 'isAutoCreated' => 'f', 'createdAt' => '2026-01-01', 'updatedAt' => '2026-01-01'];
        $this->legacyInsert('User', ['id' => 'u1', 'name' => 'Filip Bibic', 'email' => 'filip@bibich.co', 'hashedPassword' => '$2b$12$x', 'role' => 'ADMIN', 'createdAt' => '2026-01-01', 'canEditOrders' => 't', 'canSeeShippedOrders' => 't']);
        $this->legacyInsert('User', ['id' => 'u2', 'name' => 'Claude', 'email' => 'admin@example.com', 'hashedPassword' => '$2b$12$x', 'role' => 'ADMIN', 'createdAt' => '2026-01-01', 'canEditOrders' => 'f', 'canSeeShippedOrders' => 'f']);
        $this->legacyInsert('InventoryItem', $item + ['id' => 'i1', 'name' => 'R3', 'sku' => 'R3', 'currentStock' => '10.5', 'defaultPrice' => '12']);
        $this->legacyInsert('InventoryItem', $item + ['id' => 'i2', 'name' => 'Seed', 'sku' => 'FP-REDWINE-001', 'currentStock' => '0']);
        $this->legacyInsert('InventoryImage', ['id' => 'm1', 'url' => 'https://blob.example/inventory/i1-1.webp', 'sortOrder' => 0, 'inventoryItemId' => 'i1']);
        $this->legacyInsert('InventoryImage', ['id' => 'm2', 'url' => 'https://blob.example/inventory/i1-2.png', 'sortOrder' => 1, 'inventoryItemId' => 'i1']);
        $this->legacyInsert('Customer', ['id' => 'c1', 'companyName' => 'Co', 'email' => 'c1@x.hr', 'isActive' => 't', 'rebatePercent' => 0, 'excludeFromStats' => 'f', 'hidePrices' => 'f', 'isAgency' => 'f', 'allowSingleBottle' => 'f', 'createdAt' => '2026-01-01', 'updatedAt' => '2026-01-01', 'orderToken' => 'tok']);
        $this->legacyInsert('Order', ['id' => 'o1', 'orderNumber' => '266104', 'status' => 'SHIPPED', 'totalAmount' => '553.8', 'customerId' => 'c1', 'createdById' => 'u2', 'isBackorder' => 'f', 'shippingPaidByUs' => 'f', 'isConsignment' => 'f', 'createdAt' => '2026-03-01', 'updatedAt' => '2026-03-01']);
        $this->legacyInsert('OrderItem', ['id' => 'l1', 'quantity' => 5, 'unitType' => 'cases', 'unitPrice' => '110.76', 'total' => '553.8', 'orderId' => 'o1', 'inventoryItemId' => 'i1']);
        $this->legacyInsert('StockMovement', ['id' => 's1', 'type' => 'MANUAL_IN', 'quantity' => '10.5', 'inventoryItemId' => 'i1', 'createdById' => 'u1', 'createdAt' => '2026-03-01', 'isReconciliation' => 'f']);
        $this->legacyInsert('StockMovement', ['id' => 's2', 'type' => 'ADJUSTMENT', 'quantity' => '1', 'inventoryItemId' => 'i2', 'createdById' => 'u1', 'createdAt' => '2026-03-01', 'isReconciliation' => 'f']);
        $this->legacyInsert('Cost', ['id' => 'k1', 'date' => '2026-02-27', 'totalAmount' => '256.25', 'vatAmount' => '51.25', 'currency' => 'EUR', 'category' => 'Supplies', 'status' => 'PAID', 'createdAt' => '2026-02-27', 'updatedAt' => '2026-02-27', 'createdById' => 'u1', 'isLegacy' => 'f']);
        $inflow = ['date' => '2026-03-01', 'totalAmount' => '100', 'currency' => 'EUR', 'category' => 'Wine Sales', 'status' => 'PENDING', 'createdAt' => '2026-03-01', 'updatedAt' => '2026-03-01', 'createdById' => 'u1', 'isCancelled' => 'f', 'type' => 'INVOICE', 'isLegacy' => 'f', 'isCreditNote' => 'f'];
        $this->legacyInsert('Inflow', array_merge($inflow, ['id' => 'f1', 'isCancelled' => 't', 'cancelledByInflowId' => 'f2']));
        $this->legacyInsert('Inflow', array_merge($inflow, ['id' => 'f2', 'isCreditNote' => 't']));
        $this->legacyInsert('Inflow', array_merge($inflow, ['id' => 'f3', 'totalAmount' => '486']));
    }

    private function importAll(): Tenant
    {
        $tenant = $this->createTenant();
        $ctx = new ImportContext($tenant, DB::connection('legacy'), new IdMap($tenant->getKey()), new Report);
        app(LegacyImporter::class)->run($ctx);

        return $tenant;
    }

    /** @return array<string, array{legacy: string, new: string, status: string}> keyed by "area: check" */
    private function reconcile(Tenant $tenant): array
    {
        $out = [];
        foreach (app(Reconciler::class)->run($tenant, DB::connection('legacy')) as $r) {
            $out["{$r['area']}: {$r['check']}"] = $r;
        }

        return $out;
    }

    public function test_clean_import_reconciles_with_no_failures(): void
    {
        $rows = $this->reconcile($this->importAll());

        $failed = array_keys(array_filter($rows, fn ($r) => $r['status'] === 'FAIL'));
        $this->assertSame([], $failed);

        $this->assertSame(['1', '1'], [$rows['users: memberships (test accounts excluded)']['legacy'], $rows['users: memberships (test accounts excluded)']['new']]);
        $this->assertSame('1', $rows['inventory: inventory items']['new']);                       // seed item excluded on both sides
        $this->assertSame('1', $rows['stock: stock movements']['new']);
        $this->assertSame('1', $rows['finance: inflows']['new']);                                  // cancelled pair excluded
        $this->assertSame('486.00', $rows['finance: open receivables (pending, not credit notes)']['new']);
        $this->assertSame('553.80', $rows['orders: sum of order totals']['new']);
        $this->assertSame('1', $rows['integrity: rows attributed to the Legacy Import user']['new']); // order made by the test account
        $this->assertSame('2', $rows['integrity: inventory images still to be copied (run legacy:copy-media)']['new']);
    }

    public function test_reconcile_fails_when_the_import_is_damaged(): void
    {
        $this->legacyInsert('Customer', ['id' => 'c2', 'companyName' => 'Lonely', 'email' => 'c2@x.hr', 'isActive' => 't', 'rebatePercent' => 0, 'excludeFromStats' => 'f', 'hidePrices' => 'f', 'isAgency' => 'f', 'allowSingleBottle' => 'f', 'createdAt' => '2026-01-01', 'updatedAt' => '2026-01-01']);
        $tenant = $this->importAll();

        DB::table('order_items')->delete();
        DB::table('customers')->where('company_name', 'Lonely')->delete();
        DB::table('costs')->update(['total_amount' => 1]);

        $rows = $this->reconcile($tenant);

        $this->assertSame('FAIL', $rows['orders: order lines']['status']);
        $this->assertSame('FAIL', $rows['orders: sum of line totals']['status']);
        $this->assertSame('FAIL', $rows['customers: customers']['status']);
        $this->assertSame('FAIL', $rows['finance: sum of cost totals']['status']);
        $this->assertSame('PASS', $rows['orders: orders']['status']);
    }

    public function test_reconcile_command_exit_code_follows_the_result(): void
    {
        Storage::fake('local');
        $tenant = $this->importAll();

        $this->assertSame(0, Artisan::call('legacy:reconcile', ['--tenant' => $tenant->slug]));

        DB::table('orders')->delete();
        $this->assertSame(1, Artisan::call('legacy:reconcile', ['--tenant' => $tenant->slug]));
        $this->assertSame(1, Artisan::call('legacy:reconcile', ['--tenant' => 'nope']));
    }

    public function test_media_copy_moves_files_records_size_and_is_idempotent(): void
    {
        $tenant = $this->importAll();
        Http::fake([
            'blob.example/inventory/i1-1.webp' => Http::response('WEBPDATA', 200, ['Content-Type' => 'image/webp']),
            'blob.example/inventory/i1-2.png' => Http::response('PNG', 200, ['Content-Type' => 'application/octet-stream']),
        ]);

        $result = app(MediaCopier::class)->copy($tenant, DB::connection('legacy'));

        $this->assertSame([2, 0, 0, 11], [$result['copied'], $result['skipped'], $result['failed'], $result['bytes']]);
        $key = 'tenants/'.$tenant->getKey().'/inventory/i1-1.webp';
        $this->assertTrue($this->store->exists($key));
        $this->assertSame('image/webp', $this->store->contentTypes[$key]);
        $this->assertSame('image/png', $this->store->contentTypes['tenants/'.$tenant->getKey().'/inventory/i1-2.png'], 'non-image content type falls back to the file extension');
        $this->assertSame(8, (int) DB::table('inventory_images')->where('object_key', $key)->value('size_bytes'));

        $again = app(MediaCopier::class)->copy($tenant, DB::connection('legacy'));
        $this->assertSame([0, 2, 0], [$again['copied'], $again['skipped'], $again['failed']]);
        Http::assertSentCount(2);

        $forced = app(MediaCopier::class)->copy($tenant, DB::connection('legacy'), force: true);
        $this->assertSame(2, $forced['copied']);
    }

    public function test_media_copy_reports_failures_and_dry_run_writes_nothing(): void
    {
        $tenant = $this->importAll();
        $state = new class
        {
            public bool $recovered = false;
        };
        Http::fake(function ($request) use ($state) {
            if (str_contains($request->url(), 'i1-2')) {
                return $state->recovered ? Http::response('PNG', 200, ['Content-Type' => 'image/png']) : Http::response('', 404);
            }

            return Http::response('WEBPDATA', 200, ['Content-Type' => 'image/webp', 'Content-Length' => '8']);
        });

        $dry = app(MediaCopier::class)->copy($tenant, DB::connection('legacy'), dryRun: true);
        $this->assertSame([1, 1], [$dry['copied'], $dry['failed']]);
        $this->assertSame([], $this->store->objects);
        $this->assertSame(0, (int) DB::table('inventory_images')->sum('size_bytes'));

        $real = app(MediaCopier::class)->copy($tenant, DB::connection('legacy'));
        $this->assertSame([1, 1], [$real['copied'], $real['failed']]);
        $this->assertStringContainsString('HTTP 404', $real['failures'][0]);

        // The failed one is retried on the next run; the good one is not re-downloaded.
        $state->recovered = true;
        $retry = app(MediaCopier::class)->copy($tenant, DB::connection('legacy'));
        $this->assertSame([1, 1, 0], [$retry['copied'], $retry['skipped'], $retry['failed']]);
    }
}
