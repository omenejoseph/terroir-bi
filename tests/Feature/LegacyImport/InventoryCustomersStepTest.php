<?php

declare(strict_types=1);

namespace Tests\Feature\LegacyImport;

use App\Enums\CustomerType;
use App\Models\Customer;
use App\Models\InventoryItem;
use App\Services\LegacyImport\IdMap;
use App\Services\LegacyImport\ImportContext;
use App\Services\LegacyImport\LegacyImporter;
use App\Services\LegacyImport\Report;
use App\Services\LegacyImport\Steps\CustomersStep;
use App\Tenancy\Contracts\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\InteractsWithTenancy;
use Tests\Concerns\LegacyFixture;
use Tests\TestCase;

class InventoryCustomersStepTest extends TestCase
{
    use InteractsWithTenancy;
    use LegacyFixture;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createLegacySchema();
    }

    /** @param array<string, mixed> $o */
    private function item(string $id, string $sku, array $o = []): void
    {
        $this->legacyInsert('InventoryItem', $o + [
            'id' => $id, 'name' => "Item {$sku}", 'sku' => $sku, 'category' => 'FINISHED', 'unit' => 'bottles',
            'currentStock' => '1659.0400000001', 'isActive' => 't', 'bottlesPerCase' => 12, 'isForSale' => 't',
            'sortOrder' => 0, 'packSize' => 1, 'hideFromPortal' => 'f', 'isAutoCreated' => 'f',
            'createdAt' => '2026-02-26 10:00:00', 'updatedAt' => '2026-02-27 10:00:00',
        ]);
    }

    /** @param array<string, mixed> $o */
    private function customer(string $id, array $o = []): void
    {
        $this->legacyInsert('Customer', $o + [
            'id' => $id, 'companyName' => "Co {$id}", 'contactName' => '', 'email' => "{$id}@x.hr", 'isActive' => 't',
            'rebatePercent' => 5, 'excludeFromStats' => 'f', 'hidePrices' => 'f', 'isAgency' => 'f', 'allowSingleBottle' => 'f',
            'createdAt' => '2026-02-26 10:00:00', 'updatedAt' => '2026-02-27 10:00:00',
        ]);
    }

    private function import(): ImportContext
    {
        $tenant = $this->createTenant();
        $ctx = new ImportContext($tenant, DB::connection('legacy'), new IdMap($tenant->getKey()), new Report);
        app(LegacyImporter::class)->run($ctx, ['pricing_tiers', 'inventory', 'customers']);
        app(TenantContext::class)->makeCurrent($tenant); // importer releases it; reads below are tenant-scoped

        return $ctx;
    }

    public function test_customer_type_rules_match_the_backfill(): void
    {
        $this->assertSame(CustomerType::Agency, CustomersStep::type('Restaurant', true));
        $this->assertSame(CustomerType::Retail, CustomersStep::type('Retailer / Shop', false));
        $this->assertSame(CustomerType::Shipshop, CustomersStep::type('Consignment partner', false));
        $this->assertSame(CustomerType::Other, CustomersStep::type('Other', false));
        $this->assertSame(CustomerType::Wholesale, CustomersStep::type(null, false));
        $this->assertSame(CustomerType::Wholesale, CustomersStep::type('Hotel', false));
    }

    public function test_inventory_money_stock_images_and_seed_skip(): void
    {
        $this->item('i1', 'LOTR32026', ['defaultPrice' => '12.000000000000000000', 'costPerUnit' => '5.4199999999', 'salesUnit' => 'cases', 'minStock' => '1200']);
        $this->item('i2', 'X2', ['salesUnit' => 'units', 'defaultPrice' => null, 'isActive' => 'f']);
        $this->item('i3', 'FP-REDWINE-001');
        $this->item('i4', 'BAD', ['category' => 'WEIRD']);
        $this->legacyInsert('InventoryImage', ['id' => 'm1', 'url' => 'https://blob.example/inventory/i1-1.webp', 'sortOrder' => 0, 'inventoryItemId' => 'i1']);
        $this->legacyInsert('RecipeItem', ['id' => 'r1', 'quantity' => '2.5', 'outputId' => 'i1', 'inputId' => 'i2']);
        $this->legacyInsert('RecipeItem', ['id' => 'r2', 'quantity' => '1', 'outputId' => 'i1', 'inputId' => 'i3']); // seed input → skipped
        $this->legacyInsert('RecipeItem', ['id' => 'r3', 'quantity' => '1', 'outputId' => 'i1', 'customName' => 'Label', 'customUnit' => 'pcs', 'customCost' => '0.07']);

        $ctx = $this->import();

        $this->assertSame(2, InventoryItem::query()->count());
        $i1 = InventoryItem::query()->where('sku', 'LOTR32026')->firstOrFail();
        $this->assertSame(1200, (int) DB::table('inventory_items')->where('id', $i1->id)->value('default_price'));
        $this->assertSame(542, (int) DB::table('inventory_items')->where('id', $i1->id)->value('cost_per_unit'));
        $this->assertSame('cases', (string) $i1->sales_unit);
        $this->assertSame('bottles', (string) InventoryItem::query()->where('sku', 'X2')->firstOrFail()->sales_unit);
        $this->assertNull(DB::table('inventory_items')->where('sku', 'X2')->value('default_price'));
        $this->assertFalse((bool) DB::table('inventory_items')->where('sku', 'X2')->value('is_active'));

        $key = DB::table('inventory_images')->value('object_key');
        $this->assertSame('tenants/'.$ctx->tenant->getKey().'/inventory/i1-1.webp', $key);
        $this->assertSame('image/webp', DB::table('inventory_images')->value('content_type'));

        $this->assertSame(2, DB::table('recipe_items')->count());
        $this->assertSame(7, (int) DB::table('recipe_items')->whereNull('input_id')->value('custom_cost'));
        $this->assertSame(1, $ctx->report->counts()['recipes']['skipped']);
        $this->assertSame(2, $ctx->report->counts()['inventory']['skipped']);
    }

    public function test_customers_prices_tokens_and_email_synthesis(): void
    {
        $this->legacyInsert('PricingTier', ['id' => 't1', 'name' => 'Gold', 'rebatePercent' => 7.5, 'createdAt' => '2026-01-01', 'updatedAt' => '2026-01-01']);
        $this->item('i1', 'A1');
        $this->customer('c1', ['email' => 'Ana@X.hr', 'pricingTierId' => 't1', 'orderToken' => 'tok1', 'customerType' => 'Retailer / Shop', 'oib' => 'HR123', 'city' => '']);
        $this->customer('c2', ['email' => 'ana@x.hr']);     // case-insensitive duplicate
        $this->customer('c3', ['email' => '']);               // blank
        $this->customer('c4', ['isAgency' => 't']);
        $this->legacyInsert('CustomerPrice', ['id' => 'p1', 'price' => '9.99', 'inventoryItemId' => 'i1', 'customerId' => 'c1']);
        $this->legacyInsert('CustomerProductOverride', ['id' => 'o1', 'customerId' => 'c1', 'inventoryItemId' => 'i1', 'visible' => 'f']);
        $this->legacyInsert('CustomerPrice', ['id' => 'p2', 'price' => '1', 'inventoryItemId' => 'gone', 'customerId' => 'c1']);

        $ctx = $this->import();

        $c1 = Customer::query()->where('oib', 'HR123')->firstOrFail();
        $this->assertSame('ana@x.hr', $c1->email);
        $this->assertSame(CustomerType::Retail, $c1->customer_type);
        $this->assertSame('tok1', $c1->order_token);
        $this->assertNull($c1->city);
        $this->assertNull($c1->contact_name);
        $this->assertSame($ctx->ids->get('PricingTier', 't1'), $c1->pricing_tier_id);
        $this->assertEquals(7.5, DB::table('pricing_tiers')->value('rebate_percent'));

        $emails = Customer::query()->pluck('email')->all();
        $this->assertContains('legacy+c2@import.invalid', $emails);
        $this->assertContains('legacy+c3@import.invalid', $emails);
        $this->assertSame(CustomerType::Agency, Customer::query()->where('company_name', 'Co c4')->firstOrFail()->customer_type);

        $this->assertSame(999, (int) DB::table('customer_prices')->value('price'));
        $this->assertSame(1, DB::table('customer_prices')->count());
        $this->assertSame(1, $ctx->report->counts()['customer_prices']['skipped']);
        $this->assertFalse((bool) DB::table('customer_product_overrides')->value('visible'));
    }

    public function test_rerun_updates_in_place(): void
    {
        $this->item('i1', 'A1');
        $this->customer('c1');
        $ctx = $this->import();
        $this->legacyInsert('Customer', ['id' => 'c9'] + (array) DB::connection('legacy')->table('Customer')->first());
        DB::connection('legacy')->table('Customer')->where('id', 'c9')->update(['email' => 'c9@x.hr']);
        DB::connection('legacy')->table('Customer')->where('id', 'c1')->update(['companyName' => 'Renamed']);

        app(LegacyImporter::class)->run(new ImportContext($ctx->tenant, DB::connection('legacy'), $ctx->ids, new Report), ['customers']);
        app(TenantContext::class)->makeCurrent($ctx->tenant);

        $this->assertSame(2, Customer::query()->withoutGlobalScopes()->count());
        $this->assertSame('Renamed', DB::table('customers')->where('id', $ctx->ids->get('Customer', 'c1'))->value('company_name'));
        $this->assertSame(1, DB::table('inventory_items')->count());
    }
}
