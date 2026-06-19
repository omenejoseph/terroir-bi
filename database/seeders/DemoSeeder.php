<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Actions\Cellar\AddCellarAdditionAction;
use App\Actions\Cellar\AddCellarAnalysisAction;
use App\Actions\Cellar\AddCellarProcessAction;
use App\Actions\Cellar\AddTastingNoteAction;
use App\Actions\Cellar\CreateBottlingAction;
use App\Actions\Cellar\CreateEnologicalProductAction;
use App\Actions\Cellar\CreateFermentationTemplateAction;
use App\Actions\Cellar\CreateTransferAction;
use App\Actions\Cellar\CreateVesselAction;
use App\Actions\Cellar\CreateWineLotAction;
use App\Actions\Costs\CreateCostAction;
use App\Actions\Finance\CreateInflowAction;
use App\Actions\Inventory\ProduceItemAction;
use App\Actions\Inventory\SetRecipeAction;
use App\Actions\Orders\AddOrderCommentAction;
use App\Actions\Orders\CloseConsignmentAction;
use App\Actions\Orders\CreateOrderAction;
use App\Actions\Orders\RecordConsignmentSaleAction;
use App\Actions\Orders\UpdateOrderStatusAction;
use App\Actions\Pricing\UpsertCustomerPriceAction;
use App\Actions\Pricing\UpsertTierPriceAction;
use App\Actions\Suppliers\CreateSupplierOrderAction;
use App\Actions\Suppliers\UpdateSupplierOrderStatusAction;
use App\Actions\Suppliers\UpsertSupplierPriceItemAction;
use App\Actions\Tasks\CreateWorkOrderAction;
use App\Actions\Tenancy\AddTenantMemberAction;
use App\Actions\Tenancy\CreateTenantAction;
use App\Enums\CellarTransferType;
use App\Enums\CostCategory;
use App\Enums\CostStatus;
use App\Enums\CustomerType;
use App\Enums\GrapeContractStatus;
use App\Enums\HarvestEntryStatus;
use App\Enums\HarvestPlanStatus;
use App\Enums\HarvestSource;
use App\Enums\InflowStatus;
use App\Enums\IntakeBookingStatus;
use App\Enums\InventoryCategory;
use App\Enums\OrderStatus;
use App\Enums\ParcelOwnership;
use App\Enums\PaymentMethod;
use App\Enums\PhenologyStage;
use App\Enums\PlanUnit;
use App\Enums\PressFractionType;
use App\Enums\ProductionPlanStatus;
use App\Enums\SalesUnit;
use App\Enums\StockMovementType;
use App\Enums\SupplierOrderStatus;
use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Enums\TenantRole;
use App\Enums\VesselType;
use App\Enums\VineyardApplicationType;
use App\Enums\WineLotStatus;
use App\Enums\WineType;
use App\Enums\WorkOrderCategory;
use App\Models\Cost;
use App\Models\CropEstimate;
use App\Models\Customer;
use App\Models\CustomerProductOverride;
use App\Models\GrapeContract;
use App\Models\HarvestEntry;
use App\Models\HarvestPlan;
use App\Models\IntakeBooking;
use App\Models\InventoryItem;
use App\Models\MaturitySample;
use App\Models\Order;
use App\Models\PhenologyLog;
use App\Models\PressFraction;
use App\Models\PricingTier;
use App\Models\ProductionPlan;
use App\Models\ProductionPlanRow;
use App\Models\Supplier;
use App\Models\TastingReport;
use App\Models\Tenant;
use App\Models\Vessel;
use App\Models\VineyardApplication;
use App\Models\VineyardParcel;
use App\Models\WineLot;
use App\Services\Inventory\StockLedger;
use App\Services\Vineyards\CropYieldEstimator;
use App\Services\Vineyards\HarvestIntakeService;
use App\Tenancy\Contracts\TenantContext;
use Database\Seeders\Support\SeedMedia;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Rich, realistic demo data for the "Demo Winery" tenant, modelled on the real
 * Dalmatian producer BIBICh so every module has meaningful content to exercise.
 *
 * Images are uploaded through the app's own upload pipeline (presign → PUT →
 * verify) so they genuinely land in the configured uploads bucket (R2). The
 * source files are bundled under database/seeders/assets/images so a full
 * `migrate:fresh --seed` reproduces everything offline.
 *
 * Idempotent at the tenant level: if the demo tenant already exists the seeder
 * does nothing (recreate the DB with `php artisan migrate:fresh --seed`).
 */
class DemoSeeder extends Seeder
{
    private Tenant $tenant;

    private string $adminId;

    private SeedMedia $media;

    /** @var array<string, string> role label => user id */
    private array $team = [];

    public function run(): void
    {
        if (Tenant::query()->where('slug', 'demo')->exists()) {
            $this->command->warn('Demo tenant already exists — skipping DemoSeeder (use migrate:fresh to rebuild).');

            return;
        }

        $this->command->info('Provisioning demo tenant…');
        $result = app(CreateTenantAction::class)->execute([
            'name' => 'Demo Winery',
            'slug' => 'demo',
            'currency' => 'EUR',
            'locale' => 'en',
            'admin' => [
                'first_name' => 'Filip',
                'last_name' => 'Bibić',
                'email' => 'test@example.com',
                'password' => 'password',
            ],
        ]);

        $this->tenant = $result['tenant'];
        $this->adminId = $result['user']->getKey();

        $context = app(TenantContext::class);
        $context->makeCurrent($this->tenant);

        try {
            $this->media = new SeedMedia;

            $this->seedTeam();
            $suppliers = $this->seedSuppliers();
            $catalog = $this->seedInventory();
            $this->seedRecipesAndProduction($catalog['finished'], $catalog['raw']);
            $tiers = $this->seedPricing($catalog['finished']);
            $customers = $this->seedCustomers($tiers, $catalog['finished']);
            $orders = $this->seedOrders($customers, $catalog['finished']);
            $this->seedReorderHistory($catalog['finished']);
            $this->seedStockAdjustments($catalog['finished']);
            $this->seedVintageTransitions($catalog['finished']);
            $this->seedSupplierOrders($suppliers, $catalog['raw']);
            $this->seedCosts($suppliers, $catalog['finished']);
            $this->seedInflows($customers, $orders);
            $cellar = $this->seedCellar($suppliers, $catalog['finished']);
            $this->seedVineyards($suppliers, $cellar['free_tank']);
            $this->seedWorkOrders($cellar);
            $this->seedProduction($catalog['finished']);
            $this->bulkFill();
            $this->seedTrends();

            $this->command->info('✅ Demo data seeded. Login: test@example.com / password');
        } finally {
            $context->forget();
        }
    }

    /**
     * Pad the main paginated lists past one page (25/page) so pagination is
     * visible in the demo. Runs inside the tenant context after the curated data.
     */
    private function bulkFill(): void
    {
        $this->command->info('Padding lists so pagination is visible…');

        $demoSuppliers = [];
        for ($i = 1; $i <= 30; $i++) {
            $demoSuppliers[] = Supplier::create([
                'company_name' => sprintf('Demo Supplier %02d', $i),
                'email' => sprintf('supplier%02d@demo.example', $i),
                'city' => 'Zadar',
                'country' => 'HR',
            ]);
            InventoryItem::create([
                'name' => sprintf('Demo Dry Good %02d', $i),
                'sku' => sprintf('DMO-DG-%03d', $i),
                'category' => 'RAW_MATERIAL',
                'unit' => 'units',
                'current_stock' => '100.000',
            ]);
        }

        $costAction = app(CreateCostAction::class);
        for ($i = 1; $i <= 30; $i++) {
            $amount = 10000 + $i * 500;
            $costAction->execute([
                'category' => 'Operations',
                'description' => sprintf('Operating expense %02d', $i),
                'status' => CostStatus::Paid->value,
                'payment_method' => PaymentMethod::BankTransfer->value,
                'total_amount' => $amount,
                // Gross amount includes 25% Croatian PDV → VAT portion is amount/5.
                'vat_amount' => intdiv($amount, 5),
                // Each operating expense is billed by a supplier.
                'supplier_id' => $demoSuppliers[($i - 1) % count($demoSuppliers)]->getKey(),
            ], [
                ['description' => sprintf('Item %02d', $i), 'quantity' => '1', 'unit_price' => $amount, 'category' => 'Operations'],
            ], $this->adminId);
        }

        // A high-stock sellable wine backs the filler orders without overdrawing.
        $fillerWine = InventoryItem::create([
            'name' => 'Demo Reserve (filler)',
            'sku' => 'DMO-WINE',
            'category' => 'FINISHED',
            'unit' => 'bottles',
            'sales_unit' => 'bottles',
            'bottles_per_case' => 6,
            'current_stock' => '5000.000',
            'is_for_sale' => true,
            'default_price' => 1500,
            'cost_per_unit' => 600,
        ]);

        $orderAction = app(CreateOrderAction::class);
        $inflowAction = app(CreateInflowAction::class);
        // Cycle channels so revenue-by-channel shows a real split.
        $channelTypes = [CustomerType::Wholesale, CustomerType::Retail, CustomerType::Agency];
        for ($i = 1; $i <= 30; $i++) {
            $customer = Customer::create([
                'company_name' => sprintf('Demo Account %02d', $i),
                'email' => sprintf('account%02d@demo.example', $i),
                'city' => 'Split',
                'country' => 'HR',
                'customer_type' => $channelTypes[$i % 3]->value,
            ]);
            $order = $orderAction->execute($customer, $this->adminId, [
                'status' => OrderStatus::Shipped->value,
                'items' => [['inventory_item_id' => $fillerWine->getKey(), 'quantity' => 4 + ($i % 5)]],
            ]);
            // Date these older (60–176 days) so they fill YTD + the lists without
            // washing out the recent up/down trend signal seeded in seedTrends().
            $order->created_at = now()->subDays(60 + ($i - 1) * 4);
            $order->save();
            $inflowAction->execute([
                'customer_id' => $customer->getKey(),
                'order_id' => $order->getKey(),
                'amount' => (int) $order->total_amount->getMinorAmount() ?: 9000,
                'status' => InflowStatus::Received->value,
                'category' => 'Wine sales',
            ], $this->adminId);
        }

        // Payroll lines drive Employee Cost %, Operating Margin and Rev/Employee
        // (headcount = distinct salary descriptions). Marketing is seeded elsewhere.
        foreach (['Ana Horvat', 'Marko Babić', 'Ivana Kovač', 'Petar Marić'] as $j => $name) {
            $costAction->execute([
                'category' => CostCategory::Salary->value,
                'description' => sprintf('Payroll — %s', $name),
                'status' => CostStatus::Paid->value,
                'payment_method' => PaymentMethod::BankTransfer->value,
                'total_amount' => 250000 + $j * 30000,
                'date' => now()->subDays(5),
            ], [
                ['description' => 'Monthly salary', 'quantity' => '1', 'unit_price' => 250000 + $j * 30000, 'category' => CostCategory::Salary->value],
            ], $this->adminId);
        }
    }

    /**
     * Curated revenue history so the dashboard's YoY cards (today / MTD / YTD) and
     * the channel trend pills show real, mixed up/down movement. Orders are created
     * directly (no stock effect) with explicit dates — including a matching set one
     * year ago so the year-over-year comparisons resolve instead of showing "—".
     */
    private function seedTrends(): void
    {
        $this->command->info('Seeding revenue history for YoY + channel trends…');

        $byChannel = [];
        foreach ([CustomerType::Wholesale, CustomerType::Retail, CustomerType::Agency] as $type) {
            $byChannel[$type->value] = Customer::create([
                'company_name' => 'Trend · '.ucfirst(strtolower($type->value)),
                'email' => 'trend-'.strtolower($type->value).'@demo.example',
                'city' => 'Zagreb',
                'country' => 'HR',
                'customer_type' => $type->value,
            ]);
        }

        $seq = 0;
        $place = function (string $type, $date, int $amount) use (&$seq, $byChannel): void {
            $seq++;
            $order = Order::create([
                'order_number' => sprintf('TREND-%03d', $seq),
                'status' => OrderStatus::Shipped->value,
                'total_amount' => $amount,
                'customer_id' => $byChannel[$type]->getKey(),
                'created_by_id' => $this->adminId,
                'is_consignment' => false,
            ]);
            $order->created_at = $date;
            $order->save();
        };

        $now = now();

        // ── This year ──────────────────────────────────────────────────────────
        // Today (feeds current today / MTD / YTD).
        $place('WHOLESALE', $now->copy(), 12000);
        $place('RETAIL', $now->copy(), 4000);
        // Current 30-day window (channel-trend "current").
        $place('RETAIL', $now->copy()->subDays(6), 9000);
        $place('AGENCY', $now->copy()->subDays(12), 3000);
        $place('WHOLESALE', $now->copy()->subDays(20), 8000);
        // Prior 30-day window (channel-trend "previous"), tuned for a mix:
        // wholesale DOWN (heavier before), retail/agency UP (lighter before).
        $place('WHOLESALE', $now->copy()->subDays(38), 26000);
        $place('RETAIL', $now->copy()->subDays(45), 2000);
        $place('AGENCY', $now->copy()->subDays(52), 1500);
        // Earlier this year (YTD current).
        $place('WHOLESALE', $now->copy()->subDays(120), 18000);
        $place('AGENCY', $now->copy()->subDays(200), 9000);

        // ── Last year (so today / MTD / YTD comparisons resolve) ─────────────────
        // One year ago today feeds last-year today, MTD and YTD windows; making it
        // larger than today's total renders a "down" today trend.
        $place('WHOLESALE', $now->copy()->subYear(), 14000);
        $place('RETAIL', $now->copy()->subYear(), 6000);
        // Earlier last year (last-year YTD only).
        $place('WHOLESALE', $now->copy()->subYear()->subDays(90), 11000);
        $place('AGENCY', $now->copy()->subYear()->subDays(180), 7000);
    }

    // ── Team ────────────────────────────────────────────────────────────────

    private function seedTeam(): void
    {
        $members = [
            ['Marko', 'Kovač', 'sales@demo.test', [TenantRole::Sales, TenantRole::Orders]],
            ['Ivana', 'Horvat', 'cellar@demo.test', [TenantRole::Cellar]],
            ['Petra', 'Novak', 'orders@demo.test', [TenantRole::Orders, TenantRole::Inventory]],
            ['Luka', 'Babić', 'manager@demo.test', [TenantRole::Manager]],
        ];

        foreach ($members as [$first, $last, $email, $roles]) {
            $membership = app(AddTenantMemberAction::class)->execute($this->tenant, [
                'first_name' => $first,
                'last_name' => $last,
                'email' => $email,
                'password' => 'password',
                'roles' => array_map(fn (TenantRole $r) => $r->value, $roles),
            ]);
            $this->team[$roles[0]->value] = (string) $membership->user_id;
        }
    }

    private function assignee(TenantRole $role): ?string
    {
        return $this->team[$role->value] ?? null;
    }

    // ── Inventory (BIBICh portfolio) ─────────────────────────────────────────

    /**
     * @return array{finished: array<string, InventoryItem>, raw: array<string, InventoryItem>, produced: InventoryItem}
     */
    private function seedInventory(): array
    {
        $this->command->info('Seeding inventory + uploading bottle images…');

        // Dry goods (raw materials) used by supplier orders and production recipes.
        $raw = [
            'bottle' => $this->rawItem('Bordeaux bottle 750ml', 'PKG-BOT-750', 'units', 42, '20000'),
            'cork' => $this->rawItem('Natural cork 44mm', 'PKG-CORK', 'units', 18, '25000'),
            'label' => $this->rawItem('Front label (estate)', 'PKG-LBL', 'units', 9, '30000'),
            'capsule' => $this->rawItem('Capsule (matte black)', 'PKG-CAP', 'units', 6, '30000'),
            'carton' => $this->rawItem('Carton box (6-pack)', 'PKG-CRT6', 'units', 85, '4000'),
        ];

        // Finished wines: [code, name, variety, vintage, type, price€c, cost€c, image, stock]
        $wines = [
            ['debit', 'BIBICh Debit', 'Debit', '2023', WineType::White, 1400, 500, 'bibich-debit.jpg', '1800'],
            ['posip', 'BIBICh Pošip', 'Pošip', '2022', WineType::White, 1600, 600, 'bibich-posip.jpg', '900'],
            ['bilo', 'BIBICh Bilo', 'Debit', '2022', WineType::White, 1300, 480, 'bibich-bilo.jpg', '1200'],
            ['bas_de_fain', 'BIBICh Bas de Fain', 'Fumé Blanc', '2021', WineType::White, 2600, 1000, 'bibich-bas-de-fain.jpg', '600'],
            ['r5', 'BIBICh R5 Riserva', 'Debit · Pošip · Maraština', '2019', WineType::White, 3500, 1400, 'bibich-r5.jpg', '480'],
            ['bas_de_bas', 'BIBICh Bas de Bas', 'Debit (skin contact)', '2020', WineType::Orange, 2800, 1100, 'bibich-bas-de-bas.jpg', '420'],
            ['think_pink', 'BIBICh Think Pink Rosé', 'Plavina', '2023', WineType::Rose, 1500, 550, 'bibich-think-pink-rose.jpg', '900'],
            ['plavina', 'BIBICh Plavina', 'Plavina', '2021', WineType::Red, 1800, 700, 'bibich-plavina.jpg', '850'],
            ['babic', 'BIBICh Babić', 'Babić', '2020', WineType::Red, 2200, 900, 'bibich-babic.jpg', '760'],
            ['lasin', 'BIBICh Lasin', 'Lasin', '2020', WineType::Red, 2400, 950, 'bibich-lasin.jpg', '540'],
            ['crno', 'BIBICh Crno', 'Plavina · Lasin · Babić', '2021', WineType::Red, 2000, 800, 'bibich-crno.jpg', '700'],
            ['r6', 'BIBICh R6 Riserva', 'Plavina · Lasin · Babić', '2018', WineType::Red, 3800, 1500, 'bibich-r6.jpg', '360'],
            ['sangreal_merlot', 'BIBICh Sangreal Merlot', 'Merlot', '2018', WineType::Red, 4500, 1800, 'bibich-sangreal-merlot.jpg', '300'],
            ['sangreal_shiraz', 'BIBICh Sangreal Shiraz', 'Shiraz', '2018', WineType::Red, 4800, 1900, 'bibich-sangreal-shiraz.jpg', '280'],
            ['brut', 'BIBICh Brut', 'Debit (méthode traditionnelle)', 'NV', WineType::Sparkling, 3000, 1200, 'bibich-brut.jpg', '600'],
            ['brut_rose', 'BIBICh Brut Rosé', 'Plavina (méthode traditionnelle)', 'NV', WineType::Sparkling, 3200, 1300, 'bibich-brut-rose.jpg', '500'],
            ['ambra', 'BIBICh Ambra Prošek', 'Dessert (prošek)', '2017', WineType::Dessert, 4000, 1600, 'bibich-ambra-prosek.jpg', '240'],
        ];

        // Entry-level, high-volume SKUs sold by the case (6-pack) rather than the
        // bottle — so case-unit ordering / performance can be exercised.
        $caseSold = ['bilo', 'crno'];

        $finished = [];
        foreach ($wines as [$code, $name, $variety, $vintage, $type, $price, $cost, $image, $stock]) {
            $salesUnit = in_array($code, $caseSold, true) ? SalesUnit::Cases->value : SalesUnit::Bottles->value;
            $item = InventoryItem::create([
                'name' => $name,
                'sku' => 'WINE-'.strtoupper($code),
                'description' => $variety.' — '.($vintage === 'NV' ? 'non-vintage' : $vintage).'. Indigenous Dalmatian wine from Plastovo, Skradin.',
                'category' => InventoryCategory::Finished->value,
                'group' => 'Wine',
                'subcategory' => $type->value,
                'vintage' => $vintage,
                'unit' => 'bottles',
                'sales_unit' => $salesUnit,
                'unit_size' => $code === 'ambra' ? '500ml' : '750ml',
                'bottles_per_case' => 6,
                'pack_size' => 6,
                'default_price' => $price,
                'cost_per_unit' => $cost,
                'is_for_sale' => true,
                'is_active' => true,
                'min_stock' => '60.000',
            ]);

            // Bottle shot (+ a lifestyle/cellar second image on the flagship reds).
            $images = [$image];
            if (in_array($code, ['r6', 'sangreal_merlot', 'babic'], true)) {
                $images[] = 'cellar-barrels.jpg';
            }
            $this->attachImages($item, $images);

            // Opening stock through the ledger so movements + current_stock agree.
            app(StockLedger::class)->record($item, StockMovementType::ManualIn, $stock, 'Opening inventory');

            $finished[$code] = $item;
        }

        // A newly-bottled release whose cost hasn't been entered yet — its order
        // lines snapshot a null cost, so it surfaces in the analytics
        // "lines without cost" panel until someone sets a cost.
        $uncosted = InventoryItem::create([
            'name' => 'BIBICh Riserva 2023 (new release)',
            'sku' => 'WINE-RIS23',
            'description' => 'Plavina · Lasin · Babić — 2023. Cost not yet recorded.',
            'category' => InventoryCategory::Finished->value,
            'group' => 'Wine',
            'subcategory' => WineType::Red->value,
            'vintage' => '2023',
            'unit' => 'bottles',
            'sales_unit' => SalesUnit::Bottles->value,
            'unit_size' => '750ml',
            'bottles_per_case' => 6,
            'default_price' => 3600,
            // cost_per_unit intentionally omitted (uncosted).
            'is_for_sale' => true,
            'is_active' => true,
            'min_stock' => '60.000',
        ]);
        $this->attachImages($uncosted, ['bibich-r6.jpg']);
        app(StockLedger::class)->record($uncosted, StockMovementType::ManualIn, '300', 'Opening inventory');
        $finished['uncosted'] = $uncosted;

        // Tech sheets (PDF) on the riservas; a spec document on Debit.
        $this->attachSpecSheets($finished);

        // A produced-from-recipe item to exercise the recipe / produce path.
        $produced = $this->seedProducedItem($raw, $finished);

        return ['finished' => $finished, 'raw' => $raw, 'produced' => $produced];
    }

    /**
     * @param  array<string, InventoryItem>  $finished
     */
    private function attachSpecSheets(array $finished): void
    {
        $this->attachPdf($finished['r5'], 'BIBICh R5 Riserva — Technical Sheet', 'tech');
        $this->attachPdf($finished['r6'], 'BIBICh R6 Riserva — Technical Sheet', 'tech');
        $this->attachPdf($finished['debit'], 'BIBICh Debit — Allergen & Analysis', 'doc');
    }

    private function rawItem(string $name, string $sku, string $unit, int $costMinor, string $stock): InventoryItem
    {
        $item = InventoryItem::create([
            'name' => $name,
            'sku' => $sku,
            'category' => InventoryCategory::RawMaterial->value,
            'group' => 'Dry goods',
            'unit' => $unit,
            'sales_unit' => SalesUnit::Bottles->value,
            'cost_per_unit' => $costMinor,
            'is_for_sale' => false,
            'is_active' => true,
            'min_stock' => '2000.000',
        ]);

        app(StockLedger::class)->record($item, StockMovementType::ManualIn, $stock, 'Opening inventory');

        return $item;
    }

    /**
     * @param  array<string, InventoryItem>  $raw
     * @param  array<string, InventoryItem>  $finished
     */
    private function seedProducedItem(array $raw, array $finished): InventoryItem
    {
        $cuvee = InventoryItem::create([
            'name' => 'BIBICh Estate Cuvée (bottled)',
            'sku' => 'WINE-CUVEE',
            'description' => 'House cuvée bottled in-house from dry goods + bulk wine.',
            'category' => InventoryCategory::Finished->value,
            'group' => 'Wine',
            'subcategory' => WineType::Red->value,
            'vintage' => '2021',
            'unit' => 'bottles',
            'sales_unit' => SalesUnit::Bottles->value,
            'unit_size' => '750ml',
            'bottles_per_case' => 6,
            'default_price' => 1900,
            'cost_per_unit' => 760,
            'is_for_sale' => true,
            'is_active' => true,
            'min_stock' => '60.000',
        ]);
        $this->attachImages($cuvee, ['bibich-cuvee.jpg']);

        // Recipe: 1 of each dry good per produced bottle.
        app(SetRecipeAction::class)->execute($cuvee, [
            ['input_id' => $raw['bottle']->getKey(), 'quantity' => '1'],
            ['input_id' => $raw['cork']->getKey(), 'quantity' => '1'],
            ['input_id' => $raw['label']->getKey(), 'quantity' => '1'],
            ['input_id' => $raw['capsule']->getKey(), 'quantity' => '1'],
        ]);

        // Run a production batch — consumes inputs, adds finished stock.
        app(ProduceItemAction::class)->execute($cuvee, '600');

        return $cuvee->refresh();
    }

    /**
     * Bottling recipes (dry goods per finished bottle) on several estate wines,
     * plus actual production runs from those recipes — consuming dry goods and
     * adding finished stock (ProductionIn/Out movements). This gives the
     * Production plan a real bill of materials to explode, and the stock history
     * real recipe-driven production to show.
     *
     * @param  array<string, InventoryItem>  $finished
     * @param  array<string, InventoryItem>  $raw
     */
    private function seedRecipesAndProduction(array $finished, array $raw): void
    {
        $this->command->info('Seeding recipes + production runs…');
        $setRecipe = app(SetRecipeAction::class);
        $produce = app(ProduceItemAction::class);

        // One bottle, cork, label and capsule per bottle; one carton per six.
        $recipe = static fn (): array => [
            ['input_id' => $raw['bottle']->getKey(), 'quantity' => '1'],
            ['input_id' => $raw['cork']->getKey(), 'quantity' => '1'],
            ['input_id' => $raw['label']->getKey(), 'quantity' => '1'],
            ['input_id' => $raw['capsule']->getKey(), 'quantity' => '1'],
            ['input_id' => $raw['carton']->getKey(), 'quantity' => '0.167'],
        ];

        foreach (['debit', 'posip', 'babic', 'r6', 'crno'] as $code) {
            $setRecipe->execute($finished[$code], $recipe());
        }

        // Bottling runs against those recipes (most recent vintage in stock).
        $produce->execute($finished['debit'], '480');
        $produce->execute($finished['crno'], '300');
        $produce->execute($finished['babic'], '240');
    }

    // ── Pricing ──────────────────────────────────────────────────────────────

    /**
     * @param  array<string, InventoryItem>  $finished
     * @return array<string, PricingTier>
     */
    private function seedPricing(array $finished): array
    {
        $distributor = PricingTier::create([
            'name' => 'Distributor',
            'description' => 'National wholesale distributors',
            'rebate_percent' => 15,
        ]);
        $horeca = PricingTier::create([
            'name' => 'HoReCa',
            'description' => 'Restaurants, hotels & bars',
            'rebate_percent' => 8,
        ]);

        // A few negotiated tier prices (per bottle, minor units).
        app(UpsertTierPriceAction::class)->execute($finished['debit'], $distributor, 1100);
        app(UpsertTierPriceAction::class)->execute($finished['r6'], $distributor, 3200);
        app(UpsertTierPriceAction::class)->execute($finished['babic'], $horeca, 2000);

        return ['distributor' => $distributor, 'horeca' => $horeca];
    }

    // ── Customers ────────────────────────────────────────────────────────────

    /**
     * @param  array<string, PricingTier>  $tiers
     * @param  array<string, InventoryItem>  $finished
     * @return array<string, Customer>
     */
    private function seedCustomers(array $tiers, array $finished): array
    {
        $customers = [];

        $customers['konzum'] = Customer::create([
            'company_name' => 'Konzum d.d.',
            'contact_name' => 'Nikola Marić',
            'email' => 'nabava@konzum.hr',
            'phone' => '+385 1 6433 000',
            'address' => 'Marijana Čavića 1a',
            'city' => 'Zagreb',
            'zip' => '10000',
            'country' => 'HR',
            'oib' => '29955634590',
            'customer_type' => CustomerType::Wholesale->value,
            'pricing_tier_id' => $tiers['distributor']->getKey(),
            'rebate_percent' => 15,
        ]);

        $customers['vinoteka'] = Customer::create([
            'company_name' => 'Vinoteka Bornstein',
            'contact_name' => 'Ana Knežević',
            'email' => 'info@bornstein.hr',
            'phone' => '+385 1 4811 361',
            'city' => 'Zagreb',
            'country' => 'HR',
            'customer_type' => CustomerType::Retail->value,
        ]);

        $customers['noel'] = Customer::create([
            'company_name' => 'Restaurant Noel',
            'contact_name' => 'Goran Kočiš',
            'email' => 'sommelier@noel.hr',
            'city' => 'Zagreb',
            'country' => 'HR',
            'customer_type' => CustomerType::Retail->value,
            'pricing_tier_id' => $tiers['horeca']->getKey(),
            'rebate_percent' => 8,
        ]);

        $customers['adriatica'] = Customer::create([
            'company_name' => 'Adriatica Wine Agency',
            'contact_name' => 'Sara Vuković',
            'email' => 'orders@adriatica.eu',
            'city' => 'Split',
            'country' => 'HR',
            'customer_type' => CustomerType::Agency->value,
            'is_agency' => true,
        ]);

        $customers['shipshop'] = Customer::create([
            'company_name' => 'Wine Ship Shop Split',
            'contact_name' => 'Marin Tomić',
            'email' => 'shop@wineship.hr',
            'city' => 'Split',
            'country' => 'HR',
            'customer_type' => CustomerType::Shipshop->value,
            'allow_single_bottle' => true,
        ]);

        $customers['private'] = Customer::create([
            'company_name' => 'Private Buyer — Đela',
            'contact_name' => 'Đela Perić',
            'email' => 'djela@example.com',
            'city' => 'Skradin',
            'country' => 'HR',
            'customer_type' => CustomerType::Other->value,
            'allow_single_bottle' => true,
        ]);

        // Internal account flagged out of stats (tasting room / staff): its orders
        // must NOT appear in any business analytics.
        $customers['internal'] = Customer::create([
            'company_name' => 'Estate Tasting Room (internal)',
            'email' => 'tastingroom@demo.test',
            'city' => 'Plastovo',
            'country' => 'HR',
            'customer_type' => CustomerType::Other->value,
            'exclude_from_stats' => true,
        ]);

        // A negotiated customer-specific price + a hidden product override.
        app(UpsertCustomerPriceAction::class)->execute($finished['r6'], $customers['noel'], 3100);
        CustomerProductOverride::create([
            'customer_id' => $customers['shipshop']->getKey(),
            'inventory_item_id' => $finished['sangreal_shiraz']->getKey(),
            'visible' => false,
        ]);

        return $customers;
    }

    // ── Orders ───────────────────────────────────────────────────────────────

    /**
     * @param  array<string, Customer>  $customers
     * @param  array<string, InventoryItem>  $finished
     * @return array<string, Order>
     */
    private function seedOrders(array $customers, array $finished): array
    {
        $this->command->info('Seeding orders…');
        $create = app(CreateOrderAction::class);
        $orders = [];

        // Standard wholesale order → progressed to Shipped.
        $orders['konzum'] = $create->execute($customers['konzum'], $this->adminId, [
            'status' => OrderStatus::Received->value,
            'notes' => 'Quarterly restock for Zagreb stores.',
            'items' => [
                ['inventory_item_id' => $finished['debit']->getKey(), 'quantity' => 120],
                ['inventory_item_id' => $finished['plavina']->getKey(), 'quantity' => 60],
                ['inventory_item_id' => $finished['r6']->getKey(), 'quantity' => 24],
                // Case-unit line (Bilo is sold by the 6-pack case).
                ['inventory_item_id' => $finished['bilo']->getKey(), 'quantity' => 30, 'unit_type' => 'cases'],
                // Gratis promo line (priced at zero → shown as "Gratis").
                ['inventory_item_id' => $finished['brut']->getKey(), 'quantity' => 6, 'unit_price' => 0],
            ],
        ]);
        app(UpdateOrderStatusAction::class)->execute($orders['konzum'], OrderStatus::InProcess, 'Picking started', $this->adminId);
        app(UpdateOrderStatusAction::class)->execute($orders['konzum'], OrderStatus::Shipped, 'Dispatched via GLS', $this->adminId);

        // HoReCa order, in process.
        $orders['noel'] = $create->execute($customers['noel'], $this->adminId, [
            'status' => OrderStatus::InProcess->value,
            'notes' => 'For summer tasting menu.',
            'items' => [
                ['inventory_item_id' => $finished['r6']->getKey(), 'quantity' => 12],
                ['inventory_item_id' => $finished['babic']->getKey(), 'quantity' => 18],
                ['inventory_item_id' => $finished['brut']->getKey(), 'quantity' => 12],
                // New release with no cost yet → this line shows in "lines without cost".
                ['inventory_item_id' => $finished['uncosted']->getKey(), 'quantity' => 6],
                // Gratis sample for the sommelier (priced at zero → "Gratis").
                ['inventory_item_id' => $finished['ambra']->getKey(), 'quantity' => 2, 'unit_price' => 0],
            ],
        ]);
        app(AddOrderCommentAction::class)->execute($orders['noel'], 'Sommelier asked for delivery before Friday service.', [], $this->adminId);

        // Retail order, just received.
        $orders['vinoteka'] = $create->execute($customers['vinoteka'], $this->adminId, [
            'status' => OrderStatus::Received->value,
            'items' => [
                ['inventory_item_id' => $finished['posip']->getKey(), 'quantity' => 24],
                ['inventory_item_id' => $finished['think_pink']->getKey(), 'quantity' => 24],
                // Case-unit line (Crno is sold by the 6-pack case).
                ['inventory_item_id' => $finished['crno']->getKey(), 'quantity' => 8, 'unit_type' => 'cases'],
            ],
        ]);

        // Single-bottle retail (ship shop).
        $orders['shipshop'] = $create->execute($customers['shipshop'], $this->adminId, [
            'status' => OrderStatus::ReadyToShip->value,
            'items' => [
                ['inventory_item_id' => $finished['ambra']->getKey(), 'quantity' => 1],
                ['inventory_item_id' => $finished['bas_de_bas']->getKey(), 'quantity' => 2],
            ],
        ]);

        // Agency (non-consignment) sale — so the AGENCY channel shows revenue too.
        $orders['agency'] = $create->execute($customers['adriatica'], $this->adminId, [
            'status' => OrderStatus::Shipped->value,
            'items' => [
                ['inventory_item_id' => $finished['posip']->getKey(), 'quantity' => 36],
                ['inventory_item_id' => $finished['debit']->getKey(), 'quantity' => 48],
            ],
        ]);

        // Private buyer — populates the OTHER channel.
        $orders['private'] = $create->execute($customers['private'], $this->adminId, [
            'status' => OrderStatus::Shipped->value,
            'items' => [
                ['inventory_item_id' => $finished['crno']->getKey(), 'quantity' => 2, 'unit_type' => 'cases'],
                ['inventory_item_id' => $finished['think_pink']->getKey(), 'quantity' => 3],
            ],
        ]);

        // Internal/excluded customer order — should be invisible to analytics.
        $create->execute($customers['internal'], $this->adminId, [
            'status' => OrderStatus::Shipped->value,
            'notes' => 'Tasting room stock — excluded from stats.',
            'items' => [
                ['inventory_item_id' => $finished['debit']->getKey(), 'quantity' => 24],
                ['inventory_item_id' => $finished['babic']->getKey(), 'quantity' => 12],
            ],
        ]);

        // Backorder (no stock deduction now).
        $orders['backorder'] = $create->execute($customers['konzum'], $this->adminId, [
            'status' => OrderStatus::Received->value,
            'is_backorder' => true,
            'backorder_date' => now()->addWeeks(4),
            'notes' => 'R5 Riserva — awaiting next release.',
            'items' => [
                ['inventory_item_id' => $finished['r5']->getKey(), 'quantity' => 60],
            ],
        ]);

        // Consignment to the agency → record a partial sale, leave the rest open.
        $orders['consignment'] = $create->execute($customers['adriatica'], $this->adminId, [
            'status' => OrderStatus::Shipped->value,
            'is_consignment' => true,
            'notes' => 'Consignment stock for agency portfolio.',
            'items' => [
                ['inventory_item_id' => $finished['sangreal_merlot']->getKey(), 'quantity' => 36],
                ['inventory_item_id' => $finished['sangreal_shiraz']->getKey(), 'quantity' => 24],
            ],
        ]);
        $firstConsignmentLine = $orders['consignment']->items()->firstOrFail();
        app(RecordConsignmentSaleAction::class)->execute(
            $orders['consignment'],
            [['order_item_id' => (string) $firstConsignmentLine->getKey(), 'quantity' => 12]],
            'Monthly sell-through report',
            $this->adminId,
        );

        // A second, fully reconciled consignment that we close out.
        $orders['consignment_closed'] = $create->execute($customers['adriatica'], $this->adminId, [
            'status' => OrderStatus::Shipped->value,
            'is_consignment' => true,
            'items' => [
                ['inventory_item_id' => $finished['lasin']->getKey(), 'quantity' => 12],
            ],
        ]);
        app(CloseConsignmentAction::class)->execute($orders['consignment_closed'], $this->adminId);

        return $orders;
    }

    /**
     * Customers with a regular ordering cadence whose last order is now overdue,
     * so the reorder radar lights up (one each of due / overdue / at-risk).
     * Orders are backdated after creation since the cadence is the whole point.
     *
     * @param  array<string, InventoryItem>  $finished
     */
    private function seedReorderHistory(array $finished): void
    {
        $this->command->info('Seeding reorder-radar history…');
        $create = app(CreateOrderAction::class);

        // [company, type, item code, bottles/order, days-ago per order (oldest→newest)]
        $accounts = [
            ['Agora Gourmet', CustomerType::Wholesale, 'debit', 6, [185, 150, 118, 100]],   // ratio ≈3.1 → at risk
            ['Konoba More', CustomerType::Retail, 'posip', 6, [126, 98, 70, 42]],           // ratio ≈1.5 → due
            ['Galija Wines', CustomerType::Wholesale, 'plavina', 12, [205, 160, 120, 80]],  // ratio ≈2.0 → overdue
        ];

        foreach ($accounts as [$name, $type, $code, $qty, $offsets]) {
            $customer = Customer::create([
                'company_name' => $name,
                'email' => Str::slug($name).'@example.com',
                'city' => 'Zagreb',
                'country' => 'HR',
                'customer_type' => $type->value,
            ]);

            foreach ($offsets as $daysAgo) {
                $order = $create->execute($customer, $this->adminId, [
                    'status' => OrderStatus::Shipped->value,
                    'items' => [['inventory_item_id' => $finished[$code]->getKey(), 'quantity' => $qty]],
                ]);
                // Backdate so the radar sees a real cadence (effective date = created_at).
                $order->created_at = now()->subDays($daysAgo);
                $order->save();
            }
        }
    }

    /**
     * Manual stock-outs (tasting samples, breakage) on wines that also sell, so
     * the inventory "exit by channel" breakdown shows more than one channel.
     *
     * @param  array<string, InventoryItem>  $finished
     */
    private function seedStockAdjustments(array $finished): void
    {
        $ledger = app(StockLedger::class);
        $ledger->record($finished['debit'], StockMovementType::ManualOut, '-18', 'TASTING', 'Tasting room pours');
        $ledger->record($finished['babic'], StockMovementType::ManualOut, '-6', 'BREAKAGE', 'Broken in transit');
        $ledger->record($finished['posip'], StockMovementType::ManualOut, '-12', 'TASTING', 'Trade tasting samples');
    }

    /**
     * Give every (numeric-vintage) wine a prior-vintage sibling plus recent exits
     * on both, so the item-detail "Vintage transition" widget has real coverage
     * to show: a fast-moving current vintage and a slower older one.
     *
     * @param  array<string, InventoryItem>  $finished
     */
    private function seedVintageTransitions(array $finished): void
    {
        $this->command->info('Seeding vintage transitions…');
        $ledger = app(StockLedger::class);

        foreach ($finished as $code => $current) {
            if ($code === 'uncosted') {
                continue; // already a standalone new release
            }
            $vintage = (string) $current->vintage;
            if (preg_match('/^\d{4}$/', $vintage) !== 1) {
                continue; // skip non-vintage (Brut, Brut Rosé)
            }

            $current->refresh();
            $curStock = (int) round((float) $current->current_stock);
            if ($curStock < 60) {
                continue; // too little stock to split a transition convincingly
            }

            // Prior vintage of the same wine (older, slower-moving).
            $opening = max(180, intdiv($curStock, 3));
            $prior = InventoryItem::create([
                'name' => $current->name,
                'sku' => $current->sku.'-'.((int) $vintage - 1),
                'description' => $current->description,
                'category' => InventoryCategory::Finished->value,
                'group' => 'Wine',
                'subcategory' => $current->subcategory,
                'vintage' => (string) ((int) $vintage - 1),
                'unit' => 'bottles',
                'sales_unit' => $current->sales_unit,
                'unit_size' => $current->unit_size,
                'bottles_per_case' => 6,
                'pack_size' => 6,
                'default_price' => $current->default_price?->getMinorAmount(),
                'cost_per_unit' => $current->cost_per_unit?->getMinorAmount(),
                'is_for_sale' => true,
                'is_active' => true,
                'min_stock' => '60.000',
            ]);
            $ledger->record($prior, StockMovementType::ManualIn, (string) $opening, 'Opening inventory (prior vintage)');
            // Older vintage winds down slowly.
            $ledger->record($prior, StockMovementType::ManualOut, '-'.max(8, intdiv($opening, 9)), 'SALE', 'Prior-vintage depletion');
            // Current vintage sells faster over the trailing window.
            $ledger->record($current, StockMovementType::ManualOut, '-'.max(12, intdiv($curStock, 4)), 'SALE', 'Recent depletion');
        }
    }

    // ── Suppliers ────────────────────────────────────────────────────────────

    /**
     * @return array<string, Supplier>
     */
    private function seedSuppliers(): array
    {
        $suppliers = [];

        $suppliers['vetropack'] = Supplier::create([
            'company_name' => 'Vetropack Straža d.d.',
            'contact_name' => 'Tomislav Jurić',
            'email' => 'prodaja@vetropack.hr',
            'city' => 'Hum na Sutli',
            'country' => 'HR',
            'payment_terms' => 'Net 30',
        ]);
        $suppliers['amorim'] = Supplier::create([
            'company_name' => 'Amorim Cork',
            'contact_name' => 'João Silva',
            'email' => 'sales@amorim.com',
            'city' => 'Santa Maria da Feira',
            'country' => 'PT',
            'payment_terms' => 'Net 45',
        ]);
        $suppliers['enartis'] = Supplier::create([
            'company_name' => 'Enartis Adriatic',
            'contact_name' => 'Marija Šarić',
            'email' => 'info@enartis.hr',
            'city' => 'Zagreb',
            'country' => 'HR',
            'payment_terms' => 'Net 30',
        ]);
        $suppliers['grower'] = Supplier::create([
            'company_name' => 'OPG Korčula Pošip (cooperant grower)',
            'contact_name' => 'Frano Žuvela',
            'email' => 'frano@opg-posip.hr',
            'city' => 'Korčula',
            'country' => 'HR',
            'payment_terms' => 'On delivery',
        ]);

        // Supplier price list entries.
        app(UpsertSupplierPriceItemAction::class)->execute($suppliers['vetropack'], [
            'description' => 'Bordeaux bottle 750ml (antique green)', 'unit' => 'units', 'unit_price' => 42,
        ]);
        app(UpsertSupplierPriceItemAction::class)->execute($suppliers['amorim'], [
            'description' => 'Natural cork 44mm', 'unit' => 'units', 'unit_price' => 18,
        ]);

        return $suppliers;
    }

    /**
     * @param  array<string, Supplier>  $suppliers
     * @param  array<string, InventoryItem>  $raw
     */
    private function seedSupplierOrders(array $suppliers, array $raw): void
    {
        $this->command->info('Seeding supplier orders…');
        $create = app(CreateSupplierOrderAction::class);

        // Received PO → triggers PURCHASE_IN stock movements + landed cost.
        $received = $create->execute(
            $suppliers['vetropack'],
            ['notes' => 'Bottle restock for 2025 bottling.', 'expected_at' => now()->subDays(3)],
            [
                ['inventory_item_id' => $raw['bottle']->getKey(), 'description' => 'Bordeaux bottle 750ml', 'quantity' => '8000', 'unit' => 'units', 'unit_price' => 41],
            ],
            $this->adminId,
        );
        app(UpdateSupplierOrderStatusAction::class)->execute($received, SupplierOrderStatus::Received);

        // Sent PO awaiting delivery.
        $sent = $create->execute(
            $suppliers['amorim'],
            ['notes' => 'Corks for spring bottling.', 'expected_at' => now()->addWeeks(2)],
            [
                ['inventory_item_id' => $raw['cork']->getKey(), 'description' => 'Natural cork 44mm', 'quantity' => '10000', 'unit' => 'units', 'unit_price' => 18],
            ],
            $this->adminId,
        );
        app(UpdateSupplierOrderStatusAction::class)->execute($sent, SupplierOrderStatus::Sent);

        // Draft PO still being prepared.
        $create->execute(
            $suppliers['enartis'],
            ['notes' => 'Lab consumables & additives.'],
            [
                ['inventory_item_id' => null, 'description' => 'Tartaric acid 25kg', 'quantity' => '4', 'unit' => 'bags', 'unit_price' => 6500],
                ['inventory_item_id' => null, 'description' => 'Selected yeast (Lalvin) 500g', 'quantity' => '10', 'unit' => 'packs', 'unit_price' => 2200],
            ],
            $this->adminId,
        );

        // Confirmed grape-delivery PO to the cooperant grower (so every supplier
        // has at least one order to show on its detail "Orders" tab).
        $grapes = $create->execute(
            $suppliers['grower'],
            ['notes' => 'Pošip grape delivery — 2025 harvest.', 'expected_at' => now()->addDays(20)],
            [
                ['inventory_item_id' => null, 'description' => 'Pošip grapes (kg)', 'quantity' => '8000', 'unit' => 'kg', 'unit_price' => 110],
            ],
            $this->adminId,
        );
        app(UpdateSupplierOrderStatusAction::class)->execute($grapes, SupplierOrderStatus::Confirmed);
    }

    // ── Costs ────────────────────────────────────────────────────────────────

    /**
     * @param  array<string, Supplier>  $suppliers
     * @param  array<string, InventoryItem>  $finished
     */
    private function seedCosts(array $suppliers, array $finished): void
    {
        $this->command->info('Seeding costs + receipt uploads…');
        $create = app(CreateCostAction::class);

        $electricity = $create->execute([
            'category' => 'Utilities',
            'description' => 'Cellar electricity — Q1',
            'reference' => 'HEP-2026-Q1',
            'status' => CostStatus::Paid->value,
            'payment_method' => PaymentMethod::BankTransfer->value,
            'total_amount' => 184500,
            'vat_amount' => 36900,
            'due_date' => now()->subDays(10),
        ], [
            ['description' => 'Electricity consumption', 'quantity' => '1', 'unit_price' => 184500, 'category' => 'Utilities'],
        ], $this->adminId);
        $this->attachReceipt($electricity, 'receipt-1.jpg', 'hep-q1.jpg');

        $marketing = $create->execute([
            'category' => 'Marketing',
            'description' => 'Vinart Grand Tasting — booth & travel',
            'reference' => 'INV-VINART-12',
            'status' => CostStatus::Approved->value,
            'payment_method' => PaymentMethod::Card->value,
            'total_amount' => 320000,
            'vat_amount' => 64000,
            'due_date' => now()->addDays(15),
        ], [
            ['description' => 'Booth fee', 'quantity' => '1', 'unit_price' => 250000, 'category' => 'Marketing'],
            ['description' => 'Travel & accommodation', 'quantity' => '1', 'unit_price' => 70000, 'category' => 'Marketing'],
        ], $this->adminId);
        $this->attachReceipt($marketing, 'receipt-2.jpg', 'vinart-invoice.jpg');

        $create->execute([
            'category' => 'Packaging',
            'description' => 'Glass delivery — landed cost',
            'reference' => 'PO bottles',
            'status' => CostStatus::Pending->value,
            'payment_method' => PaymentMethod::BankTransfer->value,
            'total_amount' => 328000,
            'supplier_id' => $suppliers['vetropack']->getKey(),
            'due_date' => now()->addDays(20),
        ], [
            ['description' => 'Bordeaux bottles 8000 @ 0.41', 'quantity' => '8000', 'unit_price' => 41, 'category' => 'Packaging'],
        ], $this->adminId);

        $create->execute([
            'category' => 'Equipment',
            'description' => 'Bottling line service',
            'status' => CostStatus::Paid->value,
            'payment_method' => PaymentMethod::Cash->value,
            'total_amount' => 95000,
        ], [
            ['description' => 'Annual maintenance', 'quantity' => '1', 'unit_price' => 95000, 'category' => 'Equipment'],
        ], $this->adminId);

        // 'Invoice'-category costs drive the invoiced / paid / unpaid-invoice cards.
        $create->execute([
            'category' => 'Invoice',
            'description' => 'Supplier invoice — glass (paid)',
            'reference' => 'SUP-INV-2201',
            'status' => CostStatus::Paid->value,
            'payment_method' => PaymentMethod::BankTransfer->value,
            'total_amount' => 150000,
            'supplier_id' => $suppliers['vetropack']->getKey(),
            'due_date' => now()->subDays(4),
        ], [
            ['description' => 'Bottles', 'quantity' => '1', 'unit_price' => 150000, 'category' => 'Invoice'],
        ], $this->adminId);

        $create->execute([
            'category' => 'Invoice',
            'description' => 'Lab services invoice (unpaid)',
            'reference' => 'LAB-INV-0077',
            'status' => CostStatus::Pending->value,
            'total_amount' => 60000,
            'supplier_id' => $suppliers['enartis']->getKey(),
            'due_date' => now()->addDays(12),
        ], [
            ['description' => 'Analyses', 'quantity' => '1', 'unit_price' => 60000, 'category' => 'Invoice'],
        ], $this->adminId);

        // Supplier-linked costs so every supplier with a price list also shows
        // non-zero total costs on its detail (built from their price items).
        $create->execute([
            'category' => 'Packaging',
            'description' => 'Cork delivery',
            'reference' => 'AMORIM-2026-03',
            'status' => CostStatus::Approved->value,
            'payment_method' => PaymentMethod::BankTransfer->value,
            'total_amount' => 180000,
            'supplier_id' => $suppliers['amorim']->getKey(),
            'due_date' => now()->addDays(18),
        ], [
            ['description' => 'Natural cork 44mm', 'quantity' => '10000', 'unit_price' => 18, 'category' => 'Packaging'],
        ], $this->adminId);

        $create->execute([
            'category' => 'Grapes',
            'description' => 'Pošip grape delivery (cooperant)',
            'reference' => 'GROWER-2025-09',
            'status' => CostStatus::Paid->value,
            'payment_method' => PaymentMethod::BankTransfer->value,
            'total_amount' => 880000,
            'supplier_id' => $suppliers['grower']->getKey(),
        ], [
            ['description' => 'Pošip grapes (kg)', 'quantity' => '8000', 'unit_price' => 110, 'category' => 'Grapes'],
        ], $this->adminId);
    }

    // ── Money in (inflows) ───────────────────────────────────────────────────

    /**
     * @param  array<string, Customer>  $customers
     * @param  array<string, Order>  $orders
     */
    private function seedInflows(array $customers, array $orders): void
    {
        $create = app(CreateInflowAction::class);

        $create->execute([
            'customer_id' => $customers['konzum']->getKey(),
            'order_id' => $orders['konzum']->getKey(),
            'amount' => (int) $orders['konzum']->total_amount->getMinorAmount() ?: 250000,
            'status' => InflowStatus::Received->value,
            'category' => 'Wine sales',
            'reference' => 'INV-2026-031',
            'payment_method' => PaymentMethod::BankTransfer->value,
        ], $this->adminId);

        $create->execute([
            'customer_id' => $customers['noel']->getKey(),
            'order_id' => $orders['noel']->getKey(),
            'amount' => 96000,
            'status' => InflowStatus::Pending->value,
            'category' => 'Wine sales',
            'reference' => 'INV-2026-032',
            'due_date' => now()->addDays(30),
        ], $this->adminId);

        $create->execute([
            'customer_id' => $customers['vinoteka']->getKey(),
            'amount' => 78000,
            'status' => InflowStatus::Received->value,
            'category' => 'Wine sales',
            'reference' => 'INV-2026-029',
            'payment_method' => PaymentMethod::Card->value,
        ], $this->adminId);

        $create->execute([
            'customer_id' => $customers['konzum']->getKey(),
            'amount' => 14000,
            'status' => InflowStatus::Received->value,
            'is_credit_note' => true,
            'category' => 'Credit note',
            'reference' => 'CN-2026-004',
            'payment_method' => PaymentMethod::BankTransfer->value,
            'notes' => 'Two corked bottles credited.',
        ], $this->adminId);

        // 'Invoice'-category inflows drive the invoiced / collected / pending cards.
        $create->execute([
            'customer_id' => $customers['konzum']->getKey(),
            'amount' => 200000,
            'status' => InflowStatus::Received->value,
            'category' => 'Invoice',
            'reference' => 'AR-INV-2026-040',
            'payment_method' => PaymentMethod::BankTransfer->value,
        ], $this->adminId);

        $create->execute([
            'customer_id' => $customers['noel']->getKey(),
            'amount' => 88000,
            'status' => InflowStatus::Pending->value,
            'category' => 'Invoice',
            'reference' => 'AR-INV-2026-041',
            'due_date' => now()->addDays(20),
        ], $this->adminId);

        // Overdue receivables (varied ages + customers) so the index summary's
        // overdue indicator and the per-row "Overdue" pill are populated.
        $create->execute([
            'customer_id' => $customers['noel']->getKey(),
            'amount' => 42000,
            'status' => InflowStatus::Pending->value,
            'category' => 'Invoice',
            'reference' => 'AR-INV-2026-042',
            'due_date' => now()->subDays(7),
        ], $this->adminId);

        $create->execute([
            'customer_id' => $customers['vinoteka']->getKey(),
            'amount' => 31500,
            'status' => InflowStatus::Pending->value,
            'category' => 'Invoice',
            'reference' => 'AR-INV-2026-043',
            'due_date' => now()->subDays(21),
        ], $this->adminId);

        $create->execute([
            'customer_id' => $customers['konzum']->getKey(),
            'amount' => 67000,
            'status' => InflowStatus::Pending->value,
            'category' => 'Wine sales',
            'reference' => 'INV-2026-033',
            'due_date' => now()->subDays(45),
        ], $this->adminId);

        // A near-term receivable to exercise the amber "Due soon" indicator (≤ 3 days).
        $create->execute([
            'customer_id' => $customers['noel']->getKey(),
            'amount' => 25000,
            'status' => InflowStatus::Pending->value,
            'category' => 'Wine sales',
            'reference' => 'INV-2026-034',
            'due_date' => now()->addDays(2),
        ], $this->adminId);

        // Received cash + paid costs in BOTH the previous and current month so the
        // analytics "Net Cash Flow" card has data on each side of its trend.
        $costAction = app(CreateCostAction::class);
        foreach ([1, 0] as $monthsAgo) {
            $date = now()->subMonthsNoOverflow($monthsAgo)->startOfMonth()->addDays(9)->toDateString();
            $create->execute([
                'customer_id' => $customers['konzum']->getKey(),
                'amount' => $monthsAgo === 1 ? 90000 : 165000,
                'status' => InflowStatus::Received->value,
                'category' => 'Wine sales',
                'date' => $date,
                'reference' => sprintf('CF-IN-%s', $monthsAgo),
                'payment_method' => PaymentMethod::BankTransfer->value,
            ], $this->adminId);
            $costAction->execute([
                'category' => 'Operations',
                'date' => $date,
                'status' => CostStatus::Paid->value,
                'total_amount' => $monthsAgo === 1 ? 130000 : 60000,
                'payment_method' => PaymentMethod::BankTransfer->value,
            ], [], $this->adminId);
        }
    }

    // ── Cellar / production ──────────────────────────────────────────────────

    /**
     * @param  array<string, Supplier>  $suppliers
     * @param  array<string, InventoryItem>  $finished
     * @return array{vessels: array<string, Vessel>, lots: array<string, WineLot>, free_tank: Vessel}
     */
    private function seedCellar(array $suppliers, array $finished): array
    {
        $this->command->info('Seeding cellar (vessels, lots, analyses, bottling)…');

        // Vessels.
        $mk = app(CreateVesselAction::class);
        $vessels = [
            'tankA' => $mk->execute(['name' => 'Inox Tank T1', 'type' => VesselType::Tank->value, 'material' => 'Stainless steel', 'capacity_liters' => '5000', 'room' => 'Main Cellar', 'location' => 'North wall']),
            'tankB' => $mk->execute(['name' => 'Inox Tank T2', 'type' => VesselType::Tank->value, 'material' => 'Stainless steel', 'capacity_liters' => '3000', 'room' => 'Main Cellar', 'location' => 'North wall']),
            'tankC' => $mk->execute(['name' => 'Inox Tank T3', 'type' => VesselType::Tank->value, 'material' => 'Stainless steel', 'capacity_liters' => '1500', 'room' => 'Main Cellar', 'location' => 'East wall']),
            'tankRack' => $mk->execute(['name' => 'Inox Tank T6', 'type' => VesselType::Tank->value, 'material' => 'Stainless steel', 'capacity_liters' => '2000', 'room' => 'Main Cellar', 'location' => 'East wall']),
            'amphora' => $mk->execute(['name' => 'Stone amphora A1', 'type' => VesselType::Amphora->value, 'material' => 'Stone', 'capacity_liters' => '1000', 'room' => 'Amphora room', 'notes' => 'Bas de Bas skin-contact fermentation.']),
            'barrique1' => $mk->execute(['name' => 'Barrique B1', 'type' => VesselType::Barrique->value, 'material' => 'French oak', 'capacity_liters' => '225', 'room' => 'Barrel hall']),
            'barrique2' => $mk->execute(['name' => 'Barrique B2', 'type' => VesselType::Barrique->value, 'material' => 'French oak', 'capacity_liters' => '225', 'room' => 'Barrel hall']),
            'faulty' => $mk->execute(['name' => 'Inox Tank T4', 'type' => VesselType::Tank->value, 'material' => 'Stainless steel', 'capacity_liters' => '2000', 'room' => 'Main Cellar', 'is_faulty' => true, 'fault_note' => 'Leaking bottom valve — do not fill.']),
        ];
        $freeTank = $mk->execute(['name' => 'Intake Tank T5', 'type' => VesselType::Tank->value, 'material' => 'Stainless steel', 'capacity_liters' => '4000', 'room' => 'Crush pad']);

        // Enological products (one linked to a supplier; used in additions).
        $ep = app(CreateEnologicalProductAction::class);
        $so2 = $ep->execute(['name' => 'Potassium metabisulphite', 'category' => 'Antioxidant', 'unit' => 'g', 'current_stock' => '50000', 'min_stock' => '5000', 'cost_per_unit' => 1, 'manufacturer' => 'Enartis', 'supplier_id' => $suppliers['enartis']->getKey(), 'so2_uplift_per_unit' => '0.5700']);
        $ep->execute(['name' => 'Tartaric acid', 'category' => 'Acidulant', 'unit' => 'g', 'current_stock' => '40000', 'min_stock' => '4000', 'cost_per_unit' => 1, 'manufacturer' => 'Enartis', 'supplier_id' => $suppliers['enartis']->getKey()]);
        $ep->execute(['name' => 'Lalvin EC-1118 yeast', 'category' => 'Yeast', 'unit' => 'g', 'current_stock' => '5000', 'min_stock' => '500', 'cost_per_unit' => 4, 'manufacturer' => 'Lallemand']);
        $ep->execute(['name' => 'Bentonite', 'category' => 'Fining', 'unit' => 'g', 'current_stock' => '20000', 'min_stock' => '2000', 'cost_per_unit' => 1]);

        // Fermentation templates.
        $ft = app(CreateFermentationTemplateAction::class);
        $ft->execute(['name' => 'Red — extended maceration', 'wine_type' => WineType::Red->value, 'yeast_strain' => 'Lalvin EC-1118', 'target_temp_min' => '24', 'target_temp_max' => '28', 'mlf' => true, 'estimated_duration' => 21, 'description' => 'Punch-down 2×/day, 21-day maceration.']);
        $ft->execute(['name' => 'White — cool ferment', 'wine_type' => WineType::White->value, 'yeast_strain' => 'Selected aromatic', 'target_temp_min' => '14', 'target_temp_max' => '16', 'mlf' => false, 'estimated_duration' => 18, 'description' => 'Cool, slow ferment to preserve aromatics.']);

        // Wine lots (in vessels).
        $mkLot = app(CreateWineLotAction::class);
        $lots = [];
        $lots['babic'] = $mkLot->execute([
            'name' => 'Babić Plastovo', 'grape_variety' => 'Babić', 'vintage' => '2024', 'vineyard' => 'Plastovo',
            'wine_type' => WineType::Red->value, 'initial_volume' => '4500', 'status' => WineLotStatus::Fermenting->value,
            'grape_price_per_kg' => 120, 'harvest_weight_kg' => '6900', 'vessel_id' => $vessels['tankA']->getKey(),
            'grapes' => [['grape_variety' => 'Babić', 'percentage' => '100.00', 'price_per_kg' => 120, 'weight_kg' => '6900']],
        ]);
        $lots['debit'] = $mkLot->execute([
            'name' => 'Debit Plastovo', 'grape_variety' => 'Debit', 'vintage' => '2024', 'vineyard' => 'Plastovo',
            'wine_type' => WineType::White->value, 'initial_volume' => '2800', 'status' => WineLotStatus::Fermenting->value,
            'grape_price_per_kg' => 90, 'harvest_weight_kg' => '4300', 'vessel_id' => $vessels['tankB']->getKey(),
            'grapes' => [['grape_variety' => 'Debit', 'percentage' => '100.00', 'price_per_kg' => 90, 'weight_kg' => '4300']],
        ]);
        $lots['basdebas'] = $mkLot->execute([
            'name' => 'Bas de Bas (skin contact)', 'grape_variety' => 'Debit', 'vintage' => '2024', 'vineyard' => 'Plastovo',
            'wine_type' => WineType::Orange->value, 'initial_volume' => '900', 'status' => WineLotStatus::Fermenting->value,
            'grape_price_per_kg' => 90, 'harvest_weight_kg' => '1400', 'vessel_id' => $vessels['amphora']->getKey(),
            'grapes' => [['grape_variety' => 'Debit', 'percentage' => '100.00', 'price_per_kg' => 90, 'weight_kg' => '1400']],
        ]);
        // An aging blend in a tank, ready to bottle.
        $lots['r6'] = $mkLot->execute([
            'name' => 'R6 Riserva blend', 'grape_variety' => 'Plavina · Lasin · Babić', 'vintage' => '2022', 'vineyard' => 'Plastovo',
            'wine_type' => WineType::Red->value, 'initial_volume' => '1200', 'status' => WineLotStatus::Aging->value,
            'grape_price_per_kg' => 130, 'harvest_weight_kg' => '1900', 'vessel_id' => $vessels['tankC']->getKey(),
            'grapes' => [
                ['grape_variety' => 'Plavina', 'percentage' => '40.00', 'price_per_kg' => 120, 'weight_kg' => '760'],
                ['grape_variety' => 'Lasin', 'percentage' => '30.00', 'price_per_kg' => 130, 'weight_kg' => '570'],
                ['grape_variety' => 'Babić', 'percentage' => '30.00', 'price_per_kg' => 140, 'weight_kg' => '570'],
            ],
        ]);

        // Analyses.
        $an = app(AddCellarAnalysisAction::class);
        $an->execute($lots['babic'], ['date' => now()->subDays(12), 'ph' => '3.55', 'total_acidity' => '5.40', 'alcohol' => '6.20', 'brix' => '9.50', 'free_so2' => '18', 'total_so2' => '42', 'temperature' => '26.0'], $this->adminId);
        $an->execute($lots['babic'], ['date' => now()->subDays(5), 'ph' => '3.62', 'total_acidity' => '5.10', 'alcohol' => '12.80', 'brix' => '2.10', 'free_so2' => '22', 'total_so2' => '48', 'temperature' => '24.0'], $this->adminId);
        $an->execute($lots['debit'], ['date' => now()->subDays(6), 'ph' => '3.25', 'total_acidity' => '6.20', 'alcohol' => '11.50', 'brix' => '3.40', 'free_so2' => '28', 'total_so2' => '70', 'temperature' => '15.0'], $this->adminId);
        $an->execute($lots['r6'], ['date' => now()->subDays(30), 'ph' => '3.70', 'total_acidity' => '5.00', 'alcohol' => '14.20', 'volatile_acidity' => '0.55', 'free_so2' => '30', 'total_so2' => '75'], $this->adminId);

        // Additions (consume SO2 stock).
        app(AddCellarAdditionAction::class)->execute($lots['babic'], ['name' => 'SO₂ at pressing', 'category' => 'Antioxidant', 'quantity' => '2250', 'unit' => 'g', 'cost_per_unit' => 1, 'enological_product_id' => $so2->getKey()], $this->adminId);
        app(AddCellarAdditionAction::class)->execute($lots['r6'], ['name' => 'SO₂ topping', 'category' => 'Antioxidant', 'quantity' => '600', 'unit' => 'g', 'cost_per_unit' => 1, 'enological_product_id' => $so2->getKey()], $this->adminId);

        // Processes.
        $pr = app(AddCellarProcessAction::class);
        $pr->execute($lots['babic'], ['kind' => 'PUMP_OVER', 'date' => now()->subDays(10), 'volume' => '2000', 'note' => 'Twice daily during peak ferment.'], $this->adminId);
        $pr->execute($lots['r6'], ['kind' => 'RACKING', 'date' => now()->subDays(28), 'volume' => '1200', 'note' => 'Racked off gross lees.'], $this->adminId);

        // Tasting report + notes.
        $report = TastingReport::create(['created_by_id' => $this->adminId, 'title' => 'Spring barrel tasting', 'date' => now()->subDays(20), 'note' => 'Pre-bottling assessment of the riserva blends.']);
        app(AddTastingNoteAction::class)->execute($lots['r6'], [
            'tasting_report_id' => $report->getKey(), 'date' => now()->subDays(20),
            'appearance' => 'Deep ruby with garnet rim', 'nose' => 'Dark cherry, dried herbs, garrigue',
            'palate' => 'Full-bodied, fine-grained tannins, long savoury finish', 'overall' => 'Excellent ageing potential', 'score' => 93,
        ], $this->adminId);

        // Rack part of the Debit lot into the small tank (within-lot transfer).
        app(CreateTransferAction::class)->execute($lots['debit'], [
            'type' => CellarTransferType::Rack->value,
            'from_vessel_id' => $vessels['tankB']->getKey(),
            'to_vessel_id' => $vessels['tankRack']->getKey(),
            'to_lot_id' => $lots['debit']->getKey(),
            'volume_liters' => '900',
            'note' => 'Racked clear off lees.',
        ], $this->adminId);

        // Bottle the aging R6 lot into the finished R6 item.
        app(CreateBottlingAction::class)->execute($lots['r6'], [
            'bottle_count' => 1200, 'bottle_volume_ml' => 750, 'inventory_item_id' => $finished['r6']->getKey(), 'date' => now()->subDays(18),
            'note' => 'R6 Riserva 2022 bottling run.',
        ], $this->adminId);

        return ['vessels' => $vessels, 'lots' => $lots, 'free_tank' => $freeTank];
    }

    // ── Vineyards ────────────────────────────────────────────────────────────

    /**
     * @param  array<string, Supplier>  $suppliers
     */
    private function seedVineyards(array $suppliers, Vessel $intakeTank): void
    {
        $this->command->info('Seeding vineyards + harvest…');

        $babicParcel = VineyardParcel::create([
            'name' => 'Plastovo — Babić block', 'grape_variety' => 'Babić', 'area_hectares' => '3.5000',
            'location' => 'Plastovo, Skradin', 'soil_type' => 'Terra rossa over karst', 'planting_year' => 2004,
            'vine_count' => 14000, 'elevation' => 180, 'slope' => '12.00', 'orientation' => 'South',
            'latitude' => '43.838000', 'longitude' => '15.943000', 'ownership' => ParcelOwnership::Own->value,
        ]);
        $debitParcel = VineyardParcel::create([
            'name' => 'Plastovo — Debit (Lučica)', 'grape_variety' => 'Debit', 'area_hectares' => '2.8000',
            'location' => 'Plastovo, Skradin', 'soil_type' => 'Limestone', 'planting_year' => 1972,
            'vine_count' => 9000, 'elevation' => 165, 'ownership' => ParcelOwnership::Own->value,
            'notes' => "Old vines planted by Alen's grandfather.",
        ]);
        $cooperant = VineyardParcel::create([
            'name' => 'Korčula — Pošip (cooperant)', 'grape_variety' => 'Pošip', 'area_hectares' => '1.6000',
            'location' => 'Čara, Korčula', 'ownership' => ParcelOwnership::Cooperant->value,
            'cooperant_supplier_id' => $suppliers['grower']->getKey(),
        ]);

        // Phenology across the season.
        foreach ([
            [$babicParcel, PhenologyStage::BudBreak, now()->subMonths(3), '100.00'],
            [$babicParcel, PhenologyStage::Flowering, now()->subMonths(2), '90.00'],
            [$babicParcel, PhenologyStage::Veraison, now()->subDays(20), '60.00'],
            [$debitParcel, PhenologyStage::BudBreak, now()->subMonths(3), '100.00'],
            [$debitParcel, PhenologyStage::FruitSet, now()->subDays(45), '80.00'],
        ] as [$parcel, $stage, $date, $pct]) {
            PhenologyLog::create(['parcel_id' => $parcel->getKey(), 'created_by_id' => $this->adminId, 'date' => $date, 'stage' => $stage->value, 'progress_percent' => $pct]);
        }

        // Maturity sampling (brix climbing toward harvest).
        foreach ([['18.5', '3.10', '8.20'], ['21.0', '3.25', '7.10'], ['23.5', '3.45', '6.20']] as $i => [$brix, $ph, $ta]) {
            MaturitySample::create(['parcel_id' => $babicParcel->getKey(), 'created_by_id' => $this->adminId, 'date' => now()->subDays(21 - $i * 7), 'brix' => $brix, 'ph' => $ph, 'total_acidity' => $ta, 'temperature' => '26.0']);
        }

        // Crop estimate (computed yield).
        $yield = app(CropYieldEstimator::class)->estimate(28, 180.0, 20, 14000);
        CropEstimate::create([
            'parcel_id' => $babicParcel->getKey(), 'created_by_id' => $this->adminId, 'date' => now()->subDays(25),
            'cluster_count' => 28, 'avg_cluster_weight' => '180.00', 'sample_vine_count' => 20,
            'estimated_yield_kg' => number_format($yield, 3, '.', ''), 'note' => 'Sampled 20 vines across the block.',
        ]);

        // Spray application with PHI.
        VineyardApplication::create([
            'parcel_id' => $babicParcel->getKey(), 'created_by_id' => $this->adminId, 'date' => now()->subDays(35),
            'type' => VineyardApplicationType::Spray->value, 'product' => 'Copper hydroxide', 'dosage' => '2 kg/ha',
            'phi_days' => 21, 'phi_end_date' => now()->subDays(14)->toDateString(), 'weather' => 'Dry, light breeze',
        ]);

        // Grape supply contract with the cooperant grower.
        GrapeContract::create([
            'supplier_id' => $suppliers['grower']->getKey(), 'parcel_id' => $cooperant->getKey(),
            'season' => '2025', 'status' => GrapeContractStatus::Active->value, 'grape_variety' => 'Pošip',
            'estimated_kg' => '8000.000', 'delivered_kg' => '0.000', 'price_per_kg' => 110,
            'min_brix' => '21.0', 'delivery_window' => 'Sept 15–30', 'payment_terms' => 'On delivery',
        ]);

        // Harvest plan + entries.
        $plan = HarvestPlan::create(['created_by_id' => $this->adminId, 'name' => '2025 Harvest', 'season' => '2025', 'status' => HarvestPlanStatus::Active->value, 'yield_ratio' => '0.650']);

        // One planned entry that we then receive (creates a wine lot).
        $entry = HarvestEntry::create([
            'harvest_plan_id' => $plan->getKey(), 'parcel_id' => $babicParcel->getKey(),
            'status' => HarvestEntryStatus::Planned->value, 'source' => HarvestSource::Own->value,
            'grape_variety' => 'Babić', 'planned_date' => now()->subDays(2), 'estimated_yield_kg' => '4200.000',
            'planned_vessel_id' => $intakeTank->getKey(),
        ]);
        // A second still-planned entry for the cooperant fruit.
        HarvestEntry::create([
            'harvest_plan_id' => $plan->getKey(), 'parcel_id' => $cooperant->getKey(),
            'status' => HarvestEntryStatus::Planned->value, 'source' => HarvestSource::Contract->value,
            'supplier_id' => $suppliers['grower']->getKey(), 'grape_variety' => 'Pošip',
            'planned_date' => now()->addDays(10), 'estimated_yield_kg' => '8000.000',
        ]);

        // Intake bookings (weighbridge slots).
        IntakeBooking::create(['harvest_plan_id' => $plan->getKey(), 'supplier_id' => $suppliers['grower']->getKey(), 'date' => now()->addDays(10), 'time_slot' => '08:00–10:00', 'grape_variety' => 'Pošip', 'estimated_kg' => '4000.000', 'grower_name' => 'OPG Korčula Pošip', 'status' => IntakeBookingStatus::Scheduled->value]);
        IntakeBooking::create(['harvest_plan_id' => $plan->getKey(), 'date' => now()->subDays(2), 'time_slot' => '10:00–12:00', 'grape_variety' => 'Babić', 'estimated_kg' => '4200.000', 'grower_name' => 'Estate', 'status' => IntakeBookingStatus::Processed->value]);

        // Receive the Babić entry → spins up a fermenting wine lot in the intake tank.
        $newLot = app(HarvestIntakeService::class)->record($entry, [
            'actual_yield_kg' => '4200', 'grape_variety' => 'Babić', 'vessel_id' => $intakeTank->getKey(),
        ], $this->adminId);

        // Press fractions off that intake.
        PressFraction::create(['harvest_entry_id' => $entry->getKey(), 'wine_lot_id' => $newLot->getKey(), 'vessel_id' => $intakeTank->getKey(), 'fraction_type' => PressFractionType::FreeRun->value, 'volume_liters' => '2200', 'yield_percent' => '80.00', 'press_program' => 'Gentle whole-cluster', 'pressure_bar' => '0.80']);
        PressFraction::create(['harvest_entry_id' => $entry->getKey(), 'wine_lot_id' => $newLot->getKey(), 'fraction_type' => PressFractionType::LightPress->value, 'volume_liters' => '500', 'yield_percent' => '18.00', 'pressure_bar' => '1.50']);
    }

    // ── Work orders ──────────────────────────────────────────────────────────

    /**
     * @param  array{vessels: array<string, Vessel>, lots: array<string, WineLot>, free_tank: Vessel}  $cellar
     */
    private function seedWorkOrders(array $cellar): void
    {
        $create = app(CreateWorkOrderAction::class);

        $create->execute([
            'title' => 'Top up barriques', 'description' => 'Top up B1 & B2 to compensate for evaporation.',
            'category' => WorkOrderCategory::Cellar->value, 'priority' => TaskPriority::Medium->value,
            'status' => TaskStatus::Todo->value, 'due_date' => now()->addDays(3),
            'assignee_id' => $this->assignee(TenantRole::Cellar), 'vessel_id' => $cellar['vessels']['barrique1']->getKey(),
        ], $this->adminId);

        $create->execute([
            'title' => 'Lab analysis — Babić ferment', 'description' => 'Daily brix/temp until dry.',
            'category' => WorkOrderCategory::Cellar->value, 'priority' => TaskPriority::High->value,
            'status' => TaskStatus::InProgress->value, 'start_date' => now()->subDays(10),
            'assignee_id' => $this->assignee(TenantRole::Cellar), 'wine_lot_id' => $cellar['lots']['babic']->getKey(),
        ], $this->adminId);

        $create->execute([
            'title' => 'Repair faulty tank valve', 'description' => 'T4 leaking bottom valve — call technician.',
            'category' => WorkOrderCategory::Maintenance->value, 'priority' => TaskPriority::High->value,
            'status' => TaskStatus::Todo->value, 'due_date' => now()->addDays(2),
            'vessel_id' => $cellar['vessels']['faulty']->getKey(),
        ], $this->adminId);

        $create->execute([
            'title' => 'Spray Plastovo parcels', 'description' => 'Preventive copper spray before forecast rain.',
            'category' => WorkOrderCategory::Vineyard->value, 'priority' => TaskPriority::Medium->value,
            'status' => TaskStatus::Done->value, 'completed_at' => now()->subDays(35),
        ], $this->adminId);

        $create->execute([
            'title' => 'Deliver order to Konzum DC', 'description' => 'Pallet to Zagreb distribution centre.',
            'category' => WorkOrderCategory::Delivery->value, 'priority' => TaskPriority::Medium->value,
            'status' => TaskStatus::Done->value, 'completed_at' => now()->subDays(1),
            'assignee_id' => $this->assignee(TenantRole::Orders),
        ], $this->adminId);

        $create->execute([
            'title' => 'Vinart Grand Tasting — booth', 'description' => 'Set up tasting booth, bring 3 cases per riserva.',
            'category' => WorkOrderCategory::Event->value, 'priority' => TaskPriority::Low->value,
            'status' => TaskStatus::Todo->value, 'due_date' => now()->addWeeks(3),
            'assignee_id' => $this->assignee(TenantRole::Manager),
        ], $this->adminId);
    }

    // ── Production plan ──────────────────────────────────────────────────────

    /**
     * @param  array<string, InventoryItem>  $finished
     */
    private function seedProduction(array $finished): void
    {
        $plan = ProductionPlan::create([
            'created_by_id' => $this->adminId,
            'name' => '2026 Bottling Plan',
            'status' => ProductionPlanStatus::Draft->value,
            'notes' => 'Planned next-vintage bottling volumes.',
        ]);

        $rows = [
            [$finished['debit'], '2024', '12000', PlanUnit::Bottles],
            [$finished['posip'], '2023', '6000', PlanUnit::Bottles],
            [$finished['babic'], '2021', '4500', PlanUnit::Bottles],
            [$finished['r6'], '2023', '3000', PlanUnit::Bottles],
        ];
        foreach ($rows as $i => [$item, $vintage, $qty, $unit]) {
            ProductionPlanRow::create([
                'plan_id' => $plan->getKey(),
                'base_item_id' => $item->getKey(),
                'new_vintage' => $vintage,
                'quantity' => $qty,
                'plan_unit' => $unit->value,
                'sort_order' => $i,
            ]);
        }
    }

    // ── Media helpers ────────────────────────────────────────────────────────

    /**
     * @param  list<string>  $files
     */
    private function attachImages(InventoryItem $item, array $files): void
    {
        $sort = 0;
        foreach ($files as $file) {
            $m = $this->media->image($file);
            $item->images()->create([
                'object_key' => $m['key'],
                'content_type' => $m['content_type'],
                'size_bytes' => $m['size'],
                'alt' => $item->name,
                'sort_order' => $sort++,
            ]);
        }
    }

    private function attachPdf(InventoryItem $item, string $title, string $kind): void
    {
        if ($kind === 'tech') {
            $m = $this->media->pdf($title, 'inventory_tech_sheet');
            $item->techSheets()->create(['object_key' => $m['key'], 'content_type' => $m['content_type'], 'size_bytes' => $m['size'], 'name' => $title.'.pdf']);

            return;
        }

        $m = $this->media->pdf($title, 'inventory_document');
        $item->documents()->create(['object_key' => $m['key'], 'content_type' => $m['content_type'], 'size_bytes' => $m['size'], 'name' => $title.'.pdf']);
    }

    private function attachReceipt(Cost $cost, string $file, string $filename): void
    {
        $m = $this->media->image($file, 'cost_attachment');
        $cost->attachments()->create([
            'object_key' => $m['key'],
            'filename' => $filename,
            'content_type' => $m['content_type'],
            'size_bytes' => $m['size'],
        ]);
    }
}
