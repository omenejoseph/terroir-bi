<?php

declare(strict_types=1);

namespace App\Services\LegacyImport;

use App\Enums\CellarTransferType;
use App\Enums\GrapeContractStatus;
use App\Enums\InflowStatus;
use App\Enums\OrderStatus;
use App\Enums\PlanUnit;
use App\Enums\TaskStatus;
use App\Models\Tenant;
use App\Services\LegacyImport\Steps\InventoryStep;
use App\Services\LegacyImport\Steps\UsersStep;
use App\Services\LegacyImport\Support\Normalize;
use App\Services\LegacyImport\Support\Sql;
use Illuminate\Database\Connection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * Compares the legacy database with what was imported, table by table. The expected
 * figure on the new side is derived from the legacy data using the same documented
 * exclusions as the import steps (test accounts, the seed item, cancelled inflow pairs,
 * whitespace-duplicate price items, …), so a PASS means "everything that should have
 * arrived did, with the same totals". Read-only on both sides.
 *
 * Statuses: PASS / FAIL for legacy-vs-new comparisons, INFO for facts worth knowing
 * (e.g. rows attributed to the fallback user) that are not errors.
 */
class Reconciler
{
    /** @var list<array{area: string, check: string, legacy: string, new: string, status: string, note: string}> */
    private array $rows = [];

    /** @var array<string, Collection<int, stdClass>> */
    private array $cache = [];

    private string $tenantId;

    private Connection $legacy;

    /** @return list<array{area: string, check: string, legacy: string, new: string, status: string, note: string}> */
    public function run(Tenant $tenant, Connection $legacy): array
    {
        $this->rows = [];
        $this->cache = [];
        $this->tenantId = (string) $tenant->getKey();
        $this->legacy = $legacy;

        $this->users();
        $this->inventory();
        $this->customers();
        $this->suppliers();
        $this->orders();
        $this->stock();
        $this->finance();
        $this->cellar();
        $this->production();
        $this->vineyards();
        $this->integrity();

        return $this->rows;
    }

    // ── comparisons ─────────────────────────────────────────────────────────

    private function users(): void
    {
        $expected = $this->legacyRows('User')->filter(fn ($u) => ! in_array(strtolower(trim((string) $u->email)), UsersStep::SKIP_EMAILS, true))->count();
        // Only users that came from the legacy data (via the id map): anyone added to the
        // tenant afterwards, or the Legacy Import placeholder, is reported separately below.
        $actual = DB::table('memberships as m')
            ->join('legacy_id_map as l', fn ($j) => $j->on('l.new_id', '=', 'm.user_id')->where('l.tenant_id', '=', $this->tenantId)->where('l.legacy_table', '=', 'User'))
            ->where('m.tenant_id', $this->tenantId)->count();
        $this->compare('users', 'memberships (test accounts excluded)', $expected, $actual, 'test/dev accounts are not migrated');

        $added = DB::table('memberships as m')->join('users as u', 'u.id', '=', 'm.user_id')
            ->where('m.tenant_id', $this->tenantId)->where('u.email', '!=', UsersStep::FALLBACK_EMAIL)
            ->whereNotIn('m.user_id', DB::table('legacy_id_map')->where('tenant_id', $this->tenantId)->where('legacy_table', 'User')->select('new_id'))
            ->count();
        $this->add('users', 'members added after the import (not from legacy)', '-', (string) $added, 'INFO', 'e.g. a platform admin or someone invited since');
    }

    private function inventory(): void
    {
        $items = $this->legacyRows('InventoryItem')->reject(fn ($i) => in_array($i->sku, InventoryStep::SEED_SKUS, true));
        $seedIds = $this->legacyRows('InventoryItem')->filter(fn ($i) => in_array($i->sku, InventoryStep::SEED_SKUS, true))->pluck('id')->flip();

        $this->count('inventory', 'inventory items', $items->count(), 'inventory_items', 'seed item excluded');
        $this->decimal('inventory', 'total current stock', $this->sum($items, 'currentStock', 3), $this->newSum('inventory_items', 'current_stock'), 'legacy stock is authoritative');
        $this->money('inventory', 'sum of default prices', $items->sum(fn ($i) => Normalize::minorOrNull($i->defaultPrice) ?? 0), $this->newSum('inventory_items', 'default_price'));
        $this->money('inventory', 'sum of unit costs', $items->sum(fn ($i) => Normalize::minorOrNull($i->costPerUnit) ?? 0), $this->newSum('inventory_items', 'cost_per_unit'));
        $this->count('inventory', 'images', $this->legacyRows('InventoryImage')->reject(fn ($r) => isset($seedIds[$r->inventoryItemId]))->count(), 'inventory_images');
        $this->count('inventory', 'recipe lines', $this->legacyRows('RecipeItem')->reject(fn ($r) => isset($seedIds[$r->outputId]) || ($r->inputId !== null && isset($seedIds[$r->inputId])))->count(), 'recipe_items');
        $this->count('inventory', 'pricing tiers', $this->legacyRows('PricingTier')->count(), 'pricing_tiers');
    }

    private function customers(): void
    {
        $this->count('customers', 'customers', $this->legacyRows('Customer')->count(), 'customers');
        $this->count('customers', 'customer categories', $this->legacyRows('CustomerCategory')->count(), 'customer_categories');
        $this->count('customers', 'customers carrying a category', $this->legacyRows('Customer')->filter(fn ($c) => Normalize::str($c->customerType) !== null)->count(), 'customers', 'the descriptive label, e.g. Restaurant', fn ($q) => $q->whereNotNull('customer_category_id'));
        $this->count('customers', 'customers with a portal token', $this->legacyRows('Customer')->filter(fn ($c) => Normalize::str($c->orderToken) !== null)->count(), 'customers', '', fn ($q) => $q->whereNotNull('order_token'));
        $this->count('customers', 'customer prices', $this->legacyRows('CustomerPrice')->count(), 'customer_prices');
        $this->count('customers', 'tier prices', $this->legacyRows('TierPrice')->count(), 'tier_prices');
        $this->count('customers', 'product visibility overrides', $this->legacyRows('CustomerProductOverride')->count(), 'customer_product_overrides');
    }

    private function suppliers(): void
    {
        $this->count('suppliers', 'suppliers', $this->legacyRows('Supplier')->count(), 'suppliers');

        $distinct = $this->legacyRows('SupplierPriceItem')->groupBy(fn ($p) => $p->supplierId.'|'.trim((string) $p->description))->count();
        $this->compare('suppliers', 'supplier price items', $distinct, DB::table('supplier_price_items')->where('tenant_id', $this->tenantId)->count(), 'whitespace duplicates collapse to the newest');
    }

    private function orders(): void
    {
        $orders = $this->legacyRows('Order')->filter(fn ($o) => OrderStatus::tryFrom((string) $o->status) !== null);

        $this->count('orders', 'orders', $orders->count(), 'orders');
        $this->money('orders', 'sum of order totals', $orders->sum(fn ($o) => Normalize::minor($o->totalAmount)), $this->newSum('orders', 'total_amount'));
        $this->money('orders', 'sum of shipping costs', $orders->sum(fn ($o) => Normalize::minor($o->shippingCost)), $this->newSum('orders', 'shipping_cost'));
        $this->count('orders', 'consignment orders', $orders->filter(fn ($o) => Normalize::bool($o->isConsignment))->count(), 'orders', '', fn ($q) => $q->where('is_consignment', true));
        $this->count('orders', 'backorders', $orders->filter(fn ($o) => Normalize::bool($o->isBackorder))->count(), 'orders', '', fn ($q) => $q->where('is_backorder', true));
        $this->count('orders', 'order lines', $this->legacyRows('OrderItem')->count(), 'order_items');
        $this->money('orders', 'sum of line totals', $this->legacyRows('OrderItem')->sum(fn ($i) => Normalize::minor($i->total)), $this->newSum('order_items', 'total'));
        $this->count('orders', 'status history entries', $this->legacyRows('OrderStatusHistory')->count(), 'order_status_histories');
        $this->count('orders', 'notes', $this->legacyRows('OrderNote')->count(), 'order_notes');

        $migratedUsers = $this->legacyRows('User')->filter(fn ($u) => ! in_array(strtolower(trim((string) $u->email)), UsersStep::SKIP_EMAILS, true))->pluck('id')->flip();
        $this->compare('orders', 'note reactions', $this->legacyRows('OrderNoteReaction')->filter(fn ($r) => isset($migratedUsers[$r->userId]))->count(), DB::table('order_note_reactions')->where('tenant_id', $this->tenantId)->count(), 'reactions by non-migrated users are dropped');
        $this->count('orders', 'consignment reports', $this->legacyRows('ConsignmentReport')->count(), 'consignment_reports');
        $this->count('orders', 'consignment report items', $this->legacyRows('ConsignmentReportItem')->count(), 'consignment_report_items');
    }

    private function stock(): void
    {
        $seedIds = $this->legacyRows('InventoryItem')->filter(fn ($i) => in_array($i->sku, InventoryStep::SEED_SKUS, true))->pluck('id')->flip();
        $moves = $this->legacyRows('StockMovement')->reject(fn ($m) => isset($seedIds[$m->inventoryItemId]));

        $this->count('stock', 'stock movements', $moves->count(), 'stock_movements', 'seed item movements excluded');
        $this->decimal('stock', 'sum of movement quantities', $this->sum($moves, 'quantity', 3), $this->newSum('stock_movements', 'quantity'));
    }

    private function finance(): void
    {
        $costs = $this->legacyRows('Cost');
        $this->count('finance', 'costs', $costs->count(), 'costs');
        $this->money('finance', 'sum of cost totals', $costs->sum(fn ($c) => Normalize::minor($c->totalAmount)), $this->newSum('costs', 'total_amount'));
        $this->money('finance', 'sum of cost VAT', $costs->sum(fn ($c) => Normalize::minorOrNull($c->vatAmount) ?? 0), $this->newSum('costs', 'vat_amount'));
        $invoicedCosts = $costs->filter(fn ($c) => $this->legacyRows('EInvoice')->contains('costId', $c->id));
        $this->count('finance', 'invoiced costs (linked to an e-invoice)', $invoicedCosts->count(), 'costs', 'drives the invoice and VAT summaries', fn ($q) => $q->where('is_invoice', true));
        $this->money('finance', 'VAT on invoiced costs', $invoicedCosts->sum(fn ($c) => Normalize::minorOrNull($c->vatAmount) ?? 0), (int) DB::table('costs')->where('tenant_id', $this->tenantId)->where('is_invoice', true)->sum('vat_amount'));
        $this->count('finance', 'cost lines', $this->legacyRows('CostItem')->count(), 'cost_items');
        $this->money('finance', 'sum of cost line totals', $this->legacyRows('CostItem')->sum(fn ($i) => Normalize::minor($i->total)), $this->newSum('cost_items', 'total'));

        // Cancelled legacy inflows are an invoice plus its reversing credit note; both halves are skipped.
        $all = $this->legacyRows('Inflow');
        $reversals = $all->pluck('cancelledByInflowId')->filter()->flip();
        $inflows = $all->reject(fn ($i) => Normalize::bool($i->isCancelled) || isset($reversals[$i->id]))
            ->filter(fn ($i) => InflowStatus::tryFrom((string) $i->status) !== null);

        $this->count('finance', 'inflows', $inflows->count(), 'inflows', 'cancelled invoice/credit-note pairs excluded');
        $this->money('finance', 'sum of inflow amounts', $inflows->sum(fn ($i) => Normalize::minor($i->totalAmount)), $this->newSum('inflows', 'amount'));
        $this->money('finance', 'sum of inflow VAT', $inflows->sum(fn ($i) => Normalize::minorOrNull($i->vatAmount) ?? 0), $this->newSum('inflows', 'vat_amount'));
        $this->money('finance', 'open receivables (pending, not credit notes)', $inflows->filter(fn ($i) => $i->status === 'PENDING' && ! Normalize::bool($i->isCreditNote))->sum(fn ($i) => Normalize::minor($i->totalAmount)), (int) DB::table('inflows')->where('tenant_id', $this->tenantId)->where('status', 'PENDING')->where('is_credit_note', false)->sum('amount'));
        $this->count('finance', 'invoiced money-received entries', $inflows->filter(fn ($i) => ! Normalize::bool($i->isCreditNote) && ($i->eInvoiceId !== null || strtoupper((string) $i->type) === 'INVOICE'))->count(), 'inflows', 'e-invoice linked or type INVOICE, never credit notes', fn ($q) => $q->where('is_invoice', true));
        $this->count('finance', 'credit notes', $inflows->filter(fn ($i) => Normalize::bool($i->isCreditNote))->count(), 'inflows', '', fn ($q) => $q->where('is_credit_note', true));
    }

    private function cellar(): void
    {
        $this->count('cellar', 'vessels', $this->legacyRows('Vessel')->count(), 'vessels');
        $this->decimal('cellar', 'total vessel capacity (L)', $this->sum($this->legacyRows('Vessel'), 'capacityLiters', 3), $this->newSum('vessels', 'capacity_liters'));
        $this->decimal('cellar', 'total vessel volume (L)', $this->sum($this->legacyRows('Vessel'), 'currentVolume', 3), $this->newSum('vessels', 'current_volume'));
        $this->count('cellar', 'wine lots', $this->legacyRows('WineLot')->count(), 'wine_lots');
        $this->decimal('cellar', 'total lot volume (L)', $this->sum($this->legacyRows('WineLot'), 'currentVolume', 3), $this->newSum('wine_lots', 'current_volume'));
        $this->money('cellar', 'sum of lot grape costs', $this->legacyRows('WineLot')->sum(fn ($l) => Normalize::minorOrNull($l->grapeCost) ?? 0), $this->newSum('wine_lots', 'grape_cost'));
        $this->count('cellar', 'lot grape components', $this->legacyRows('WineLotGrape')->count(), 'wine_lot_grapes');
        $this->count('cellar', 'vessel contents', $this->legacyRows('VesselLot')->count(), 'vessel_lots');
        $this->decimal('cellar', 'total vessel content volume (L)', $this->sum($this->legacyRows('VesselLot'), 'volume', 3), $this->newSum('vessel_lots', 'volume'));
        $this->count('cellar', 'analyses', $this->legacyRows('CellarAnalysis')->count(), 'cellar_analyses');
        $this->count('cellar', 'additions', $this->legacyRows('CellarAddition')->count(), 'cellar_additions');
        $this->count('cellar', 'processes', $this->legacyRows('CellarProcess')->count(), 'cellar_processes');
        $transfers = $this->legacyRows('CellarTransfer')->filter(fn ($t) => CellarTransferType::tryFrom((string) $t->type) !== null);
        $this->count('cellar', 'transfers', $transfers->count(), 'cellar_transfers');
        $this->decimal('cellar', 'total transferred volume (L)', $this->sum($transfers, 'volumeLiters', 3), $this->newSum('cellar_transfers', 'volume_liters'));
        $this->count('cellar', 'tasting reports', $this->legacyRows('TastingReport')->count(), 'tasting_reports');
        $this->count('cellar', 'tasting notes', $this->legacyRows('CellarTastingNote')->count(), 'cellar_tasting_notes');
        $this->count('cellar', 'enological products', $this->legacyRows('EnologicalProduct')->count(), 'enological_products');
        $this->count('cellar', 'fermentation templates', $this->legacyRows('FermentationTemplate')->count(), 'fermentation_templates');
    }

    private function production(): void
    {
        $this->count('production', 'boards', $this->legacyRows('Board')->count(), 'work_order_boards');
        $this->count('production', 'work orders', $this->legacyRows('WorkOrder')->filter(fn ($w) => TaskStatus::tryFrom((string) $w->status) !== null)->count(), 'work_orders', 'statuses without an equivalent (CANCELLED) excluded');
        $this->count('production', 'production plans', $this->legacyRows('ProductionPlan')->count(), 'production_plans');
        $this->count('production', 'production plan rows', $this->legacyRows('ProductionPlanRow')->filter(fn ($r) => PlanUnit::tryFrom((string) $r->planUnit) !== null)->count(), 'production_plan_rows');
    }

    private function vineyards(): void
    {
        $this->count('vineyards', 'parcels', $this->legacyRows('VineyardParcel')->count(), 'vineyard_parcels');
        $this->decimal('vineyards', 'total parcel area (ha)', $this->sum($this->legacyRows('VineyardParcel'), 'areaHectares', 4), $this->newSum('vineyard_parcels', 'area_hectares'), tolerance: 0.0005);
        $this->count('vineyards', 'phenology logs', $this->legacyRows('PhenologyLog')->count(), 'phenology_logs');
        $this->count('vineyards', 'crop estimates', $this->legacyRows('CropEstimate')->count(), 'crop_estimates');
        $this->decimal('vineyards', 'total estimated yield (kg)', $this->sum($this->legacyRows('CropEstimate'), 'estimatedYieldKg', 3), $this->newSum('crop_estimates', 'estimated_yield_kg'));
        $this->count('vineyards', 'treatments', $this->legacyRows('VineyardApplication')->count(), 'vineyard_applications');
        $contracts = $this->legacyRows('GrapeContract')->filter(fn ($c) => GrapeContractStatus::tryFrom((string) $c->status) !== null);
        $this->count('vineyards', 'grape contracts', $contracts->count(), 'grape_contracts');
        $this->money('vineyards', 'sum of contract prices per kg', $contracts->sum(fn ($c) => Normalize::minor($c->pricePerKg)), $this->newSum('grape_contracts', 'price_per_kg'));
        $this->count('vineyards', 'intake bookings', $this->legacyRows('IntakeBooking')->count(), 'intake_bookings');
    }

    // ── facts about the imported data (new side only) ───────────────────────

    private function integrity(): void
    {
        $tid = $this->tenantId;

        $mismatched = Sql::countGroups(DB::table('orders as o')->join('order_items as i', 'i.order_id', '=', 'o.id')->where('o.tenant_id', $tid)
            ->groupBy('o.id', 'o.total_amount')->havingRaw('sum(i.total) <> o.total_amount')->select('o.id'));
        $this->expectZero('integrity', 'orders whose total ≠ the sum of their lines', $mismatched);

        $orphans = DB::table('order_items as i')->where('i.tenant_id', $tid)->whereNull('i.inventory_item_id')->whereNull('i.custom_description')->count();
        $this->expectZero('integrity', 'order lines with neither an item nor a description', $orphans);

        $admins = DB::table('memberships')->where('tenant_id', $tid)->where('status', 'active')->whereNull('deleted_at')->where('roles', 'like', '%ADMIN%')->count();
        $this->add('integrity', 'active ADMIN members', '-', (string) $admins, $admins >= 1 ? 'PASS' : 'FAIL', 'someone must be able to administer the tenant');

        $fallback = DB::table('users')->where('email', UsersStep::FALLBACK_EMAIL)->value('id');
        if ($fallback !== null) {
            $total = 0;
            foreach (['orders' => 'created_by_id', 'costs' => 'created_by_id', 'inflows' => 'created_by_id', 'stock_movements' => 'created_by_id', 'cellar_analyses' => 'created_by_id', 'cellar_additions' => 'created_by_id', 'work_orders' => 'created_by_id', 'phenology_logs' => 'created_by_id', 'crop_estimates' => 'created_by_id', 'vineyard_applications' => 'created_by_id'] as $table => $col) {
                $total += DB::table($table)->where('tenant_id', $tid)->where($col, $fallback)->count();
            }
            $this->add('integrity', 'rows attributed to the Legacy Import user', '-', (string) $total, 'INFO', 'creator was a test account or has no equivalent');
        }

        $this->add('integrity', 'customers with a synthesized email', '-', (string) DB::table('customers')->where('tenant_id', $tid)->where('email', 'like', 'legacy+%@import.invalid')->count(), 'INFO', 'blank/duplicate email in legacy; staff should fill in');

        $drift = Sql::countGroups(DB::table('inventory_items as i')->leftJoin('stock_movements as m', 'm.inventory_item_id', '=', 'i.id')->where('i.tenant_id', $tid)
            ->groupBy('i.id', 'i.current_stock')->havingRaw('abs(i.current_stock - coalesce(sum(m.quantity), 0)) > 0.001')->select('i.id'));
        $this->add('integrity', 'items whose stock ≠ sum of movements', '-', (string) $drift, 'INFO', 'opening balances are not movements; legacy stock kept');

        $vessels = Sql::countGroups(DB::table('vessels as v')->leftJoin('vessel_lots as vl', 'vl.vessel_id', '=', 'v.id')->where('v.tenant_id', $tid)
            ->groupBy('v.id', 'v.current_volume')->havingRaw('abs(v.current_volume - coalesce(sum(vl.volume), 0)) > 0.001')->select('v.id'));
        $this->add('integrity', 'vessels whose volume ≠ sum of their contents', '-', (string) $vessels, 'INFO', 'legacy figures kept');

        $pending = DB::table('inventory_images')->where('tenant_id', $tid)->where('size_bytes', 0)->count();
        $this->add('integrity', 'inventory images still to be copied (run legacy:copy-media)', '-', (string) $pending, $pending === 0 ? 'PASS' : 'INFO', 'files still live on the old Vercel Blob host until copied');

        $prefixed = DB::table('orders')->where('tenant_id', $tid)->where('order_number', 'like', 'ORD-%')->count();
        $this->add('integrity', 'imported orders using the ORD- prefix', '-', (string) $prefixed, 'INFO', 'legacy numbers kept verbatim; the next new order is numbered from ORD-00001');
    }

    // ── helpers ─────────────────────────────────────────────────────────────

    /** @return Collection<int, stdClass> */
    private function legacyRows(string $table): Collection
    {
        return $this->cache[$table] ??= $this->legacy->table($table)->get();
    }

    /** @param Collection<int, stdClass> $rows */
    private function sum(Collection $rows, string $column, int $scale): float
    {
        return round($rows->sum(fn ($r) => (float) (Normalize::dec($r->{$column}, $scale) ?? 0)), $scale);
    }

    private function newSum(string $table, string $column): float
    {
        return (float) DB::table($table)->where('tenant_id', $this->tenantId)->sum($column);
    }

    private function count(string $area, string $label, int $expected, string $table, string $note = '', ?\Closure $scope = null): void
    {
        $q = DB::table($table)->where('tenant_id', $this->tenantId);
        if ($scope !== null) {
            $scope($q);
        }
        $this->compare($area, $label, $expected, $q->count(), $note);
    }

    private function compare(string $area, string $label, int $expected, int $actual, string $note = ''): void
    {
        $this->add($area, $label, (string) $expected, (string) $actual, $expected === $actual ? 'PASS' : 'FAIL', $note);
    }

    private function money(string $area, string $label, int|float $expected, int|float $actual): void
    {
        $fmt = fn ($v) => number_format($v / 100, 2, '.', '');
        $this->add($area, $label, $fmt($expected), $fmt($actual), (int) round($expected) === (int) round($actual) ? 'PASS' : 'FAIL', 'EUR, exact');
    }

    private function decimal(string $area, string $label, float $expected, float $actual, string $note = '', float $tolerance = 0.001): void
    {
        $this->add($area, $label, (string) $expected, (string) round($actual, 4), abs($expected - $actual) <= $tolerance ? 'PASS' : 'FAIL', $note);
    }

    private function expectZero(string $area, string $label, int $n): void
    {
        $this->add($area, $label, '0', (string) $n, $n === 0 ? 'PASS' : 'FAIL', '');
    }

    private function add(string $area, string $check, string $legacy, string $new, string $status, string $note): void
    {
        $this->rows[] = compact('area', 'check', 'legacy', 'new', 'status', 'note');
    }
}
