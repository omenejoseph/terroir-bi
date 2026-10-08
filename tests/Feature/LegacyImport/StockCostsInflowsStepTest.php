<?php

declare(strict_types=1);

namespace Tests\Feature\LegacyImport;

use App\Services\LegacyImport\IdMap;
use App\Services\LegacyImport\ImportContext;
use App\Services\LegacyImport\LegacyImporter;
use App\Services\LegacyImport\Report;
use App\Services\LegacyImport\Support\Mapping;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\InteractsWithTenancy;
use Tests\Concerns\LegacyFixture;
use Tests\TestCase;

class StockCostsInflowsStepTest extends TestCase
{
    use InteractsWithTenancy;
    use LegacyFixture;
    use RefreshDatabase;

    private const STEPS = ['users', 'pricing_tiers', 'inventory', 'customers', 'suppliers', 'orders', 'stock_movements', 'costs', 'inflows'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->createLegacySchema();

        $this->legacyInsert('User', ['id' => 'u1', 'name' => 'Filip Bibic', 'email' => 'filip@bibich.co', 'hashedPassword' => '$2b$12$x', 'role' => 'ADMIN', 'createdAt' => '2026-01-01', 'canEditOrders' => 't', 'canSeeShippedOrders' => 't']);
        $this->legacyInsert('InventoryItem', ['id' => 'i1', 'name' => 'R3', 'sku' => 'R3', 'category' => 'FINISHED', 'unit' => 'bottles', 'currentStock' => '10', 'isActive' => 't', 'bottlesPerCase' => 12, 'isForSale' => 't', 'sortOrder' => 0, 'packSize' => 1, 'hideFromPortal' => 'f', 'isAutoCreated' => 'f', 'createdAt' => '2026-01-01', 'updatedAt' => '2026-01-01']);
        $this->legacyInsert('InventoryItem', ['id' => 'i2', 'name' => 'Seed', 'sku' => 'FP-REDWINE-001', 'category' => 'FINISHED', 'unit' => 'bottles', 'currentStock' => '0', 'isActive' => 'f', 'bottlesPerCase' => 12, 'isForSale' => 't', 'sortOrder' => 0, 'packSize' => 1, 'hideFromPortal' => 'f', 'isAutoCreated' => 'f', 'createdAt' => '2026-01-01', 'updatedAt' => '2026-01-01']);
        $this->legacyInsert('Customer', ['id' => 'c1', 'companyName' => 'Co', 'email' => 'c1@x.hr', 'isActive' => 't', 'rebatePercent' => 0, 'excludeFromStats' => 'f', 'hidePrices' => 'f', 'isAgency' => 'f', 'allowSingleBottle' => 'f', 'createdAt' => '2026-01-01', 'updatedAt' => '2026-01-01']);
        $this->legacyInsert('Order', ['id' => 'o1', 'orderNumber' => '266104', 'status' => 'SHIPPED', 'totalAmount' => '0', 'customerId' => 'c1', 'createdById' => 'u1', 'isBackorder' => 'f', 'shippingPaidByUs' => 'f', 'isConsignment' => 'f', 'createdAt' => '2026-03-01', 'updatedAt' => '2026-03-01']);
    }

    /** @param array<string, mixed> $o */
    private function movement(string $id, string $type, string $qty, array $o = []): void
    {
        $this->legacyInsert('StockMovement', $o + ['id' => $id, 'type' => $type, 'quantity' => $qty, 'inventoryItemId' => 'i1', 'createdById' => 'u1', 'createdAt' => '2026-03-02 10:00:00', 'isReconciliation' => 'f']);
    }

    /** @param array<string, mixed> $o */
    private function cost(string $id, array $o = []): void
    {
        $this->legacyInsert('Cost', $o + ['id' => $id, 'date' => '2026-02-27', 'totalAmount' => '256.250000000000100000', 'currency' => 'EUR', 'category' => 'Kitchen Raw Materials', 'status' => 'PAID', 'paymentMethod' => 'Bank Transfer', 'createdAt' => '2026-02-27', 'updatedAt' => '2026-02-27', 'createdById' => 'u1', 'isLegacy' => 'f']);
    }

    /** @param array<string, mixed> $o */
    private function inflow(string $id, array $o = []): void
    {
        $this->legacyInsert('Inflow', $o + ['id' => $id, 'date' => '2026-03-01', 'totalAmount' => '486', 'currency' => 'EUR', 'category' => 'Wine Sales', 'status' => 'PENDING', 'createdAt' => '2026-03-01', 'updatedAt' => '2026-03-01', 'createdById' => 'u1', 'isCreditNote' => 'f', 'isCancelled' => 'f', 'type' => 'INVOICE', 'isLegacy' => 'f']);
    }

    private function import(): ImportContext
    {
        $tenant = $this->createTenant();
        $ctx = new ImportContext($tenant, DB::connection('legacy'), new IdMap($tenant->getKey()), new Report);
        app(LegacyImporter::class)->run($ctx, self::STEPS);

        return $ctx;
    }

    /** @param list<string> $warnings */
    private function hasWarning(array $warnings, string $needle): bool
    {
        return array_filter($warnings, fn ($w) => str_contains($w, $needle)) !== [];
    }

    public function test_payment_method_mapping(): void
    {
        $this->assertSame('bank_transfer', Mapping::paymentMethod('Bank Transfer'));
        $this->assertSame('card', Mapping::paymentMethod('Credit Card'));
        $this->assertSame('card', Mapping::paymentMethod('Card'));
        $this->assertSame('cash', Mapping::paymentMethod('Cash'));
        $this->assertSame('other', Mapping::paymentMethod('Other'));
        $this->assertSame('other', Mapping::paymentMethod('Crypto'));
        $this->assertNull(Mapping::paymentMethod(''));
        $this->assertNull(Mapping::paymentMethod(null));
    }

    public function test_stock_movements_keep_stock_and_report_orphans_and_drift(): void
    {
        $this->movement('m1', 'ORDER_DEDUCT', '-0.083333', ['reference' => '266104']);
        $this->movement('m2', 'ORDER_DEDUCT', '-6', ['reference' => 'ORD-20260678']);   // order long gone
        $this->movement('m3', 'MANUAL_IN', '9500', ['createdById' => 'ghost']);
        $this->movement('m4', 'ADJUSTMENT', '1', ['inventoryItemId' => 'i2']);          // seed item
        $this->movement('m5', 'BOGUS', '1');

        $ctx = $this->import();

        $this->assertSame(3, DB::table('stock_movements')->count());
        $m1 = DB::table('stock_movements')->where('reference', '266104')->sole();
        $this->assertEqualsWithDelta(-0.083, (float) $m1->quantity, 0.0001); // 3-decimal column
        $fallback = DB::table('users')->where('email', 'legacy-import@bibich.invalid')->value('id');
        $this->assertSame($fallback, DB::table('stock_movements')->where('type', 'MANUAL_IN')->value('created_by_id'));
        $this->assertSame(10.0, (float) DB::table('inventory_items')->where('sku', 'R3')->value('current_stock'), 'stock must stay the legacy figure');
        $this->assertSame(2, $ctx->report->counts()['stock_movements']['skipped']);
        $this->assertTrue($this->hasWarning($ctx->report->warnings(), '1 ORDER_DEDUCT movements reference an order number that no longer exists'));
        $this->assertTrue($this->hasWarning($ctx->report->warnings(), '1 of 1 items have current_stock ≠ sum of movements'));
    }

    public function test_costs_money_enums_free_text_category_and_truncation(): void
    {
        $this->legacyInsert('Supplier', ['id' => 's1', 'companyName' => 'BEL-CRO', 'isActive' => 't', 'excludeFromStats' => 'f', 'isCooperant' => 'f', 'portalEnabled' => 'f', 'createdAt' => '2026-01-01', 'updatedAt' => '2026-01-01']);
        $this->cost('k1', ['supplierId' => 's1', 'vatAmount' => '51.25', 'reference' => '89/3/2', 'paidAt' => '2026-03-01', 'bankTransactionId' => 'b1']);
        $this->cost('k2', ['status' => 'APPROVED', 'paymentMethod' => 'Credit Card', 'createdById' => 'ghost', 'vatAmount' => null]);
        $this->cost('k3', ['status' => 'CANCELLED']);
        $this->legacyInsert('CostItem', ['id' => 'ci1', 'description' => str_repeat('x', 300), 'quantity' => 2, 'unitPrice' => '1.5', 'total' => '3', 'costId' => 'k1', 'inventoryItemId' => 'i1']);
        $this->legacyInsert('CostItem', ['id' => 'ci2', 'description' => 'Orphan', 'quantity' => 1, 'unitPrice' => '1', 'total' => '1', 'costId' => 'nope']);

        $ctx = $this->import();

        $k1 = DB::table('costs')->where('reference', '89/3/2')->sole();
        $this->assertSame([25625, 5125, 'Kitchen Raw Materials', 'PAID', 'bank_transfer'], [(int) $k1->total_amount, (int) $k1->vat_amount, $k1->category, $k1->status, $k1->payment_method]);
        $this->assertNotNull($k1->supplier_id);
        $this->assertNotNull($k1->paid_at);

        $k2 = DB::table('costs')->where('status', 'APPROVED')->sole();
        $this->assertNull($k2->vat_amount);
        $this->assertSame('card', $k2->payment_method);
        $this->assertSame(DB::table('users')->where('email', 'legacy-import@bibich.invalid')->value('id'), $k2->created_by_id);

        $this->assertSame(2, DB::table('costs')->count());
        $this->assertSame(1, $ctx->report->counts()['costs']['skipped']);

        $item = DB::table('cost_items')->sole();
        $this->assertSame(300, mb_strlen($item->description), 'long descriptions are kept whole');
        $this->assertFalse($this->hasWarning($ctx->report->warnings(), 'truncated'));
        $this->assertSame(1, DB::table('cost_items')->count());
        $this->assertTrue($this->hasWarning($ctx->report->warnings(), '1 costs have line totals that differ from the header total'));
    }

    public function test_inflows_skip_cancelled_pairs_and_keep_description_in_notes(): void
    {
        $this->inflow('f1', ['reference' => '20-3-91', 'isCancelled' => 't', 'cancelledByInflowId' => 'f2']);
        $this->inflow('f2', ['reference' => '6-3-99', 'isCreditNote' => 't']);
        $this->inflow('f3', ['reference' => '21-3-91', 'customerId' => 'c1', 'description' => 'Co — 21-3-91', 'notes' => 'Call back', 'vatAmount' => '96', 'orderId' => 'o1']);
        $this->inflow('f4', ['reference' => '1-1-99', 'isCreditNote' => 't', 'totalAmount' => '12.48']);   // a genuine credit note
        $this->inflow('f5', ['reference' => 'LU0022', 'type' => 'PAYMENT', 'status' => 'RECEIVED', 'paymentMethod' => 'Bank Transfer', 'receivedAt' => '2026-03-05', 'description' => 'Plaćanje po prihvatu kartica', 'category' => 'Customer payments']);
        $this->inflow('f6', ['status' => 'WEIRD']);

        $ctx = $this->import();

        $refs = DB::table('inflows')->orderBy('reference')->pluck('reference')->all();
        $this->assertSame(['1-1-99', '21-3-91', 'LU0022'], $refs);

        $f3 = DB::table('inflows')->where('reference', '21-3-91')->sole();
        $this->assertSame(48600, (int) $f3->amount);
        $this->assertSame("Co — 21-3-91\nCall back", $f3->notes);
        $this->assertNotNull($f3->customer_id);
        $this->assertNotNull($f3->order_id);

        $this->assertTrue((bool) DB::table('inflows')->where('reference', '1-1-99')->value('is_credit_note'));
        $this->assertSame(1248, (int) DB::table('inflows')->where('reference', '1-1-99')->value('amount'));

        $f5 = DB::table('inflows')->where('reference', 'LU0022')->sole();
        $this->assertSame(['RECEIVED', 'bank_transfer'], [$f5->status, $f5->payment_method]);
        $this->assertNotNull($f5->received_at);

        $this->assertSame(3, $ctx->report->counts()['inflows']['skipped']); // 2 cancelled halves + 1 unknown status
        $this->assertSame(9600, (int) $f3->vat_amount);
        $this->assertNull($f5->vat_amount, 'no VAT recorded stays unknown, not zero');
        $this->assertTrue($this->hasWarning($ctx->report->warnings(), '1 legacy PAYMENT-type inflows'));
        $this->assertTrue($this->hasWarning($ctx->report->warnings(), '20-3-91 (cancelled invoice)'));
        $this->assertTrue($this->hasWarning($ctx->report->warnings(), '6-3-99 (reversing credit note)'));
    }

    public function test_invoice_flag_follows_the_old_apps_e_invoice_link(): void
    {
        $this->cost('k1', ['reference' => 'LINKED']);
        $this->cost('k2', ['reference' => 'PLAIN']);
        $this->legacyInsert('EInvoice', ['id' => 'e1', 'costId' => 'k1']);
        $this->legacyInsert('EInvoice', ['id' => 'e2']);   // an e-invoice linked to nothing
        $this->inflow('f1', ['reference' => 'A', 'type' => 'INVOICE']);
        $this->inflow('f2', ['reference' => 'B', 'type' => 'PAYMENT', 'status' => 'RECEIVED']);
        $this->inflow('f3', ['reference' => 'C', 'type' => 'PAYMENT', 'eInvoiceId' => 'einv']);                 // e-invoice linked
        $this->inflow('f4', ['reference' => 'D', 'type' => 'INVOICE', 'isCreditNote' => 't']);                   // credit notes never count

        $this->import();

        $this->assertTrue((bool) DB::table('costs')->where('reference', 'LINKED')->value('is_invoice'));
        $this->assertFalse((bool) DB::table('costs')->where('reference', 'PLAIN')->value('is_invoice'));
        $flags = DB::table('inflows')->orderBy('reference')->pluck('is_invoice', 'reference')->map(fn ($v) => (bool) $v)->all();
        $this->assertSame(['A' => true, 'B' => false, 'C' => true, 'D' => false], $flags);
    }
}
