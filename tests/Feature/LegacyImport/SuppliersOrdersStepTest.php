<?php

declare(strict_types=1);

namespace Tests\Feature\LegacyImport;

use App\Models\Order;
use App\Services\LegacyImport\IdMap;
use App\Services\LegacyImport\ImportContext;
use App\Services\LegacyImport\LegacyImporter;
use App\Services\LegacyImport\Report;
use App\Services\LegacyImport\Steps\UsersStep;
use App\Services\Orders\OrderNumberGenerator;
use App\Tenancy\Contracts\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\InteractsWithTenancy;
use Tests\Concerns\LegacyFixture;
use Tests\TestCase;

class SuppliersOrdersStepTest extends TestCase
{
    use InteractsWithTenancy;
    use LegacyFixture;
    use RefreshDatabase;

    private const STEPS = ['users', 'pricing_tiers', 'inventory', 'customers', 'suppliers', 'orders'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->createLegacySchema();

        $this->legacyInsert('User', ['id' => 'u1', 'name' => 'Filip Bibic', 'email' => 'filip@bibich.co', 'hashedPassword' => '$2b$12$x', 'role' => 'ADMIN', 'createdAt' => '2026-01-01', 'canEditOrders' => 't', 'canSeeShippedOrders' => 't']);
        $this->legacyInsert('InventoryItem', ['id' => 'i1', 'name' => 'R3', 'sku' => 'R3', 'category' => 'FINISHED', 'unit' => 'bottles', 'currentStock' => 10, 'isActive' => 't', 'bottlesPerCase' => 12, 'isForSale' => 't', 'sortOrder' => 0, 'packSize' => 1, 'hideFromPortal' => 'f', 'isAutoCreated' => 'f', 'createdAt' => '2026-01-01', 'updatedAt' => '2026-01-01']);
        $this->legacyInsert('Customer', ['id' => 'c1', 'companyName' => 'Co', 'email' => 'c1@x.hr', 'isActive' => 't', 'rebatePercent' => 0, 'excludeFromStats' => 'f', 'hidePrices' => 'f', 'isAgency' => 'f', 'allowSingleBottle' => 'f', 'createdAt' => '2026-01-01', 'updatedAt' => '2026-01-01']);
    }

    /** @param array<string, mixed> $o */
    private function order(string $id, string $number, array $o = []): void
    {
        $this->legacyInsert('Order', $o + [
            'id' => $id, 'orderNumber' => $number, 'status' => 'SHIPPED', 'totalAmount' => '553.800000000000100000', 'customerId' => 'c1',
            'createdById' => 'u1', 'isBackorder' => 'f', 'shippingPaidByUs' => 'f', 'isConsignment' => 'f',
            'createdAt' => '2026-03-23 09:48:31.05', 'updatedAt' => '2026-03-23 09:48:31.05',
        ]);
    }

    private function import(): ImportContext
    {
        $tenant = $this->createTenant();
        $ctx = new ImportContext($tenant, DB::connection('legacy'), new IdMap($tenant->getKey()), new Report);
        app(LegacyImporter::class)->run($ctx, self::STEPS);
        app(TenantContext::class)->makeCurrent($tenant);

        return $ctx;
    }

    public function test_orders_keep_numbers_money_flags_and_children(): void
    {
        $this->order('o1', '266104', ['shippingCost' => '12.5', 'isBackorder' => 't', 'backorderDate' => '2026-01-02']);
        $this->order('o2', 'KOMISIJA-CRAFT-OPEN-2026', ['isConsignment' => 't', 'totalAmount' => '0', 'createdById' => 'gone']);
        $this->order('o3', '266105', ['status' => 'WEIRD']);
        $this->legacyInsert('OrderItem', ['id' => 'l1', 'quantity' => 5, 'unitType' => 'cases', 'unitPrice' => '110.76', 'total' => '553.800000000000100000', 'orderId' => 'o1', 'inventoryItemId' => 'i1', 'costPerUnit' => '5.42']);
        $this->legacyInsert('OrderItem', ['id' => 'l2', 'quantity' => 2, 'unitType' => 'units', 'unitPrice' => '0', 'total' => '0', 'orderId' => 'o2', 'customDescription' => 'Open item']);
        $this->legacyInsert('OrderItem', ['id' => 'l3', 'quantity' => 1, 'unitType' => 'units', 'unitPrice' => '0', 'total' => '0', 'orderId' => 'o2', 'inventoryItemId' => 'ghost']);
        $this->legacyInsert('OrderStatusHistory', ['id' => 'h1', 'status' => 'RECEIVED', 'createdAt' => '2026-03-23 09:48:31', 'orderId' => 'o1', 'changedById' => 'u1']);
        $this->legacyInsert('OrderNote', ['id' => 'n1', 'content' => 'Call first', 'createdAt' => '2026-03-24', 'orderId' => 'o1', 'authorId' => 'u1']);
        $this->legacyInsert('OrderNoteReaction', ['id' => 'e1', 'emoji' => '👍', 'createdAt' => '2026-03-24', 'noteId' => 'n1', 'userId' => 'u1']);
        $this->legacyInsert('OrderNoteReaction', ['id' => 'e2', 'emoji' => '👍', 'createdAt' => '2026-03-24', 'noteId' => 'n1', 'userId' => 'nobody']);
        $this->legacyInsert('ConsignmentReport', ['id' => 'r1', 'orderId' => 'o2', 'kind' => 'sale', 'date' => '2026-04-01', 'createdById' => 'u1', 'createdAt' => '2026-04-01']);
        $this->legacyInsert('ConsignmentReportItem', ['id' => 'ri1', 'reportId' => 'r1', 'orderItemId' => 'l2', 'quantity' => 1, 'unitPrice' => '9.99', 'total' => '9.99']);
        // Stock already left the legacy ledger for o1 only.
        $this->legacyInsert('StockMovement', ['id' => 's1', 'type' => 'ORDER_DEDUCT', 'reference' => '266104']);

        $ctx = $this->import();

        $this->assertSame(['266104', 'KOMISIJA-CRAFT-OPEN-2026'], Order::query()->orderBy('order_number')->pluck('order_number')->all());

        $o1 = DB::table('orders')->where('order_number', '266104')->sole();
        $this->assertSame(55380, (int) $o1->total_amount);
        $this->assertSame(1250, (int) $o1->shipping_cost);
        $this->assertTrue((bool) $o1->is_backorder);
        $this->assertTrue((bool) $o1->deduct_stock);
        $this->assertNotEmpty(array_filter($ctx->report->warnings(), fn ($w) => str_contains($w, "order 266105: unknown status 'WEIRD'")));

        $o2 = DB::table('orders')->where('order_number', 'KOMISIJA-CRAFT-OPEN-2026')->sole();
        $this->assertFalse((bool) $o2->deduct_stock);
        $this->assertTrue((bool) $o2->is_consignment);
        $fallback = DB::table('users')->where('email', UsersStep::FALLBACK_EMAIL)->value('id');
        $this->assertSame($fallback, $o2->created_by_id);

        $l1 = DB::table('order_items')->where('order_id', $o1->id)->sole();
        $this->assertSame([5, 'cases', 11076, 55380, 542], [(int) $l1->quantity, $l1->unit_type, (int) $l1->unit_price, (int) $l1->total, (int) $l1->cost_per_unit]);
        $this->assertNull($l1->unit_price_gross);

        $custom = DB::table('order_items')->where('order_id', $o2->id)->orderBy('custom_description')->get();
        $this->assertCount(2, $custom);
        $line = $custom->firstOrFail();
        $this->assertSame('(inventory item no longer available)', $line->custom_description);
        $this->assertNull($line->inventory_item_id);
        $this->assertSame('bottles', $line->unit_type);

        $this->assertSame(1, DB::table('order_status_histories')->count());
        $this->assertSame(1, DB::table('order_notes')->count());
        $this->assertSame(1, DB::table('order_note_reactions')->count());
        $this->assertSame(1, $ctx->report->counts()['order_note_reactions']['skipped']);
        $this->assertSame('SALE', DB::table('consignment_reports')->value('kind'));
        $this->assertSame(1, DB::table('consignment_report_items')->count());
    }

    public function test_new_orders_start_at_ord_00001_beside_legacy_numbers(): void
    {
        $this->order('o1', '266104');
        $this->order('o2', 'KOMISIJA-CRAFT-OPEN-2026');
        $this->legacyInsert('OrderItem', ['id' => 'l1', 'quantity' => 1, 'unitType' => 'cases', 'unitPrice' => '553.8', 'total' => '553.8', 'orderId' => 'o1', 'inventoryItemId' => 'i1']);

        $this->import();

        $this->assertSame('ORD-00001', app(OrderNumberGenerator::class)->next());
    }

    public function test_header_total_mismatch_is_reported_and_rerun_is_idempotent(): void
    {
        $this->order('o1', '266104', ['totalAmount' => '100']);
        $this->legacyInsert('OrderItem', ['id' => 'l1', 'quantity' => 1, 'unitType' => 'cases', 'unitPrice' => '99', 'total' => '99', 'orderId' => 'o1', 'inventoryItemId' => 'i1']);

        $ctx = $this->import();
        $this->assertNotEmpty(array_filter($ctx->report->warnings(), fn ($w) => str_contains($w, 'order 266104: header total 10000')));

        app(LegacyImporter::class)->run(new ImportContext($ctx->tenant, DB::connection('legacy'), $ctx->ids, new Report), self::STEPS);
        $this->assertSame(1, DB::table('orders')->count());
        $this->assertSame(1, DB::table('order_items')->count());
    }

    public function test_suppliers_portal_token_price_items_and_duplicates(): void
    {
        $base = ['isActive' => 't', 'excludeFromStats' => 'f', 'isCooperant' => 'f', 'createdAt' => '2026-01-01', 'updatedAt' => '2026-01-01', 'portalEnabled' => 'f'];
        $this->legacyInsert('Supplier', array_merge($base, ['id' => 's1', 'companyName' => 'Truffles d.o.o.', 'taxId' => '', 'email' => '', 'portalToken' => 'secret', 'isCooperant' => 't']));
        $this->legacyInsert('Supplier', array_merge($base, ['id' => 's2', 'companyName' => 'Open Portal', 'portalEnabled' => 't', 'portalToken' => 'open1', 'taxId' => 'HR1']));
        $this->legacyInsert('SupplierPriceItem', ['id' => 'p1', 'description' => 'Truffle ', 'unitPrice' => '285', 'lastUpdated' => '2026-01-01', 'supplierId' => 's1', 'inventoryItemId' => 'i1']);
        $this->legacyInsert('SupplierPriceItem', ['id' => 'p2', 'description' => 'Truffle', 'unitPrice' => '290.005', 'lastUpdated' => '2026-02-01', 'supplierId' => 's1']);
        $this->legacyInsert('SupplierPriceItem', ['id' => 'p3', 'description' => 'Orphan', 'unitPrice' => '1', 'lastUpdated' => '2026-02-01', 'supplierId' => 'nope']);

        $ctx = $this->import();

        $s1 = DB::table('suppliers')->where('company_name', 'Truffles d.o.o.')->sole();
        $this->assertNull($s1->tax_id);
        $this->assertNull($s1->email);
        $this->assertNull($s1->portal_token, 'a disabled portal must not leak its token');
        $this->assertTrue((bool) $s1->is_cooperant);
        $this->assertSame('open1', DB::table('suppliers')->where('company_name', 'Open Portal')->value('portal_token'));

        $prices = DB::table('supplier_price_items')->get();
        $this->assertCount(1, $prices);
        $price = $prices->firstOrFail();
        $this->assertSame('Truffle', $price->description);
        $this->assertSame(29001, (int) $price->unit_price); // newer duplicate wins; 290.005 rounds up
        $this->assertSame(2, $ctx->report->counts()['supplier_price_items']['skipped']);
    }
}
