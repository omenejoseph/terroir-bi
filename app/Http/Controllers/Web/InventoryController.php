<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Actions\Inventory\AdjustStockAction;
use App\Actions\Inventory\ApplyInventoryCheckAction;
use App\Actions\Inventory\BulkImportInventoryItemsAction;
use App\Actions\Inventory\BulkUpdateInventoryItemsAction;
use App\Actions\Inventory\CreateInventoryItemAction;
use App\Actions\Inventory\DeleteInventoryItemAction;
use App\Actions\Inventory\DuplicateInventoryItemAction;
use App\Actions\Inventory\ProduceItemAction;
use App\Actions\Inventory\SetRecipeAction;
use App\Actions\Inventory\UpdateInventoryItemAction;
use App\DataTransferObjects\BottleAnalysisData;
use App\DataTransferObjects\InventoryCheckData;
use App\DataTransferObjects\RecipeLineData;
use App\Enums\StockMovementType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Inventory\AdjustStockRequest;
use App\Http\Requests\Inventory\BulkImportInventoryItemsRequest;
use App\Http\Requests\Inventory\BulkUpdateInventoryItemsRequest;
use App\Http\Requests\Inventory\InventoryCheckRequest;
use App\Http\Requests\Inventory\ProduceItemRequest;
use App\Http\Requests\Inventory\SetRecipeRequest;
use App\Http\Requests\Inventory\StoreInventoryItemRequest;
use App\Http\Requests\Inventory\UpdateInventoryItemRequest;
use App\Models\BottleAnalysis;
use App\Models\InventoryCheck;
use App\Models\InventoryDocument;
use App\Models\InventoryImage;
use App\Models\InventoryItem;
use App\Models\InventoryTechSheet;
use App\Models\RecipeItem;
use App\Queries\InventoryAnalyticsQuery;
use App\Queries\InventoryAttentionQuery;
use App\Queries\InventoryCoverQuery;
use App\Queries\InventoryItemStockAnalyticsQuery;
use App\Queries\InventorySpendQuery;
use App\Queries\InventoryTaxonomyQuery;
use App\Queries\ItemAuditTrailQuery;
use App\Queries\ItemCustomerAttributionQuery;
use App\Queries\ItemMovementsQuery;
use App\Queries\ItemPricingQuery;
use App\Queries\ItemStockRankingQuery;
use App\Queries\ListInventoryItemsQuery;
use App\Queries\OrderStockReconciliationQuery;
use App\Queries\VintageCoverageQuery;
use App\Services\Customers\PricingTierOptions;
use App\Services\Export\CsvExporter;
use App\Services\Inventory\InventoryItemPresenter;
use App\Services\Inventory\InventoryMediaPresenter;
use App\Services\Inventory\RecipeInputOptions;
use App\Services\Orders\OrderFormOptions;
use App\Support\InventoryItemFilters;
use App\Support\Period;
use App\Support\PerPage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Inertia counterpart of Api\InventoryItemController.
 *
 * Every read goes through the same Query + Presenter and every write through the
 * same Action as the API, so the two transports share behaviour by construction
 * rather than by discipline. The only difference is the envelope: page props and
 * redirects here, JSON there.
 */
class InventoryController extends Controller
{
    public function index(
        Request $request,
        ListInventoryItemsQuery $query,
        InventoryItemPresenter $presenter,
        InventoryTaxonomyQuery $taxonomy,
        InventoryAttentionQuery $attention,
        ItemMovementsQuery $movements,
        InventoryCoverQuery $cover,
        ItemAuditTrailQuery $auditTrail,
        ItemStockRankingQuery $ranking,
        ItemCustomerAttributionQuery $attribution,
    ): Response {
        $filters = InventoryItemFilters::fromRequest($request);
        $paginated = $query->paginate($filters, PerPage::fromRequest($request));

        return Inertia::render('Inventory/Index', [
            'items' => $presenter->page($paginated),
            'filters' => $filters,
            // "Cover" (Figma 389:1592) — one grouped query over the page's own
            // items rather than the whole tenant, so it stays cheap regardless
            // of catalogue size. See InventoryCoverQuery for the omitted
            // "Free to sell" column's reasoning (still no order-line
            // reservations to subtract, unlike Cover which is honestly
            // computable from stock movements).
            'cover' => $cover->forItems($paginated->items()),
            // The "Needs attention" band (Figma 389:1592). Counts span the whole
            // tenant, not the filtered page, so they stay stable as you filter.
            'attention' => $attention->get(),
            // Only fetched when the filter bar asks for it — the taxonomy is a
            // distinct-scan and most visits never open the category picker.
            'taxonomy' => Inertia::optional(fn () => $taxonomy->get()),
            // The Item — View drawer (Figma 378:1592) pulls the open item's
            // recent ledger entries via a partial reload, so the list itself
            // never pays for movements nobody looked at.
            'itemMovements' => Inertia::optional(function () use ($request, $movements): array {
                $item = $this->viewedItem($request);

                return $item === null ? [] : $movements->get($item, 5);
            }),
            // The drawer's Timeline section and its Provenance line's "last
            // edited by" both read this one list — see ItemAuditTrailQuery.
            'itemAuditTrail' => Inertia::optional(function () use ($request, $auditTrail): array {
                $item = $this->viewedItem($request);

                return $item === null ? [] : $auditTrail->get($item);
            }),
            // The Provenance line's ranking half — see ItemStockRankingQuery.
            'itemStockRank' => Inertia::optional(function () use ($request, $ranking): ?array {
                $item = $this->viewedItem($request);

                return $item === null ? null : $ranking->get($item);
            }),
            // "Who's buying it" — see ItemCustomerAttributionQuery.
            'itemCustomerAttribution' => Inertia::optional(function () use ($request, $attribution): array {
                $item = $this->viewedItem($request);

                return $item === null ? [] : $attribution->get($item);
            }),
        ]);
    }

    /**
     * The Item — View drawer's open item, resolved from the same `?item=`
     * query param each of its Inertia::optional props above reads.
     */
    private function viewedItem(Request $request): ?InventoryItem
    {
        $id = $request->query('item');

        if (! is_string($id) || $id === '') {
            return null;
        }

        return InventoryItem::query()->find($id);
    }

    /**
     * Product Detail (Figma 449:1577).
     *
     * Reads through exactly the services the API's stock endpoints use, so the
     * page and `GET /api/v1/.../stock-analytics` cannot report different
     * figures for the same item.
     */
    public function show(
        Request $request,
        InventoryItem $item,
        InventoryItemPresenter $presenter,
        InventoryItemStockAnalyticsQuery $analytics,
        ItemMovementsQuery $movements,
        VintageCoverageQuery $vintage,
        ItemPricingQuery $pricing,
        PricingTierOptions $tierOptions,
        OrderFormOptions $customerOptions,
        RecipeInputOptions $recipeInputs,
        InventoryMediaPresenter $media,
    ): Response {
        $item->loadMissing('firstImage');

        $period = $request->query('period');
        $period = is_string($period) && $period !== '' ? $period : '30d';

        return Inertia::render('Inventory/Show', [
            'item' => $presenter->item($item),
            'analytics' => $analytics->get($item, $period),
            'movements' => $movements->get($item),
            // Null unless the wine has sibling vintages to transition between.
            'vintageCoverage' => $vintage->get($item),
            'filters' => ['period' => $period],
            // The Pricing tab's two tables — cheap regardless of tab, so eager
            // like movements/vintageCoverage above rather than optional; this
            // page has no per-tab partial-reload scaffolding yet.
            'pricing' => [
                'tiers' => $pricing->tierPrices($item),
                'customers' => $pricing->customerOverrides($item),
            ],
            // The tab's two "Add price" pickers — a whole-tenant scan each, so
            // withheld until a dialog actually opens (see CustomerPriceDialog's
            // own pricingCatalog for the same idiom).
            'pricingTierOptions' => Inertia::optional(fn (): array => $tierOptions->list()),
            'pricingCustomerOptions' => Inertia::optional(fn (): array => $customerOptions->customers()),
            // The Recipe tab's bill of materials — the same rows and DTO
            // Api\StockController::recipe() returns, so the two transports
            // can't disagree about what an item's recipe is. Also what the
            // Produce tab checks before offering a production run.
            'recipe' => array_values($item->recipe()->with('input')->get()
                ->map(fn (RecipeItem $line): array => RecipeLineData::fromModel($line)->toArray())
                ->all()),
            // The Recipe tab's "add ingredient" picker — a whole-catalog scan,
            // so withheld until it actually opens.
            'recipeInputOptions' => Inertia::optional(fn (): array => $recipeInputs->list($item)),
            // The Images and Docs tabs. Each is one item's own small set of
            // attachments, so eager like recipe/pricing above.
            'images' => array_values($item->images()->orderBy('sort_order')->get()
                ->map(fn (InventoryImage $image): array => $media->image($image))
                ->all()),
            'techSheets' => array_values($item->techSheets()->orderBy('name')->get()
                ->map(fn (InventoryTechSheet $sheet): array => $media->techSheet($sheet))
                ->all()),
            'documents' => array_values($item->documents()->orderByDesc('id')->get()
                ->map(fn (InventoryDocument $document): array => $media->document($document))
                ->all()),
            // The Analysis tab — the same rows and DTO Api\BottleAnalysisController
            // returns, newest first.
            'bottleAnalyses' => array_values($item->bottleAnalyses()
                ->orderByDesc('analyzed_on')
                ->orderByDesc('id')
                ->get()
                ->map(fn (BottleAnalysis $analysis): array => BottleAnalysisData::fromModel($analysis)->toArray())
                ->all()),
        ]);
    }

    /**
     * The item's full stock ledger (Figma 449:1577's "Movement history" ·
     * "Export") — the same ItemMovementsQuery rows the drawer shows, but
     * unbounded rather than capped at the page's default 100.
     */
    public function exportMovements(InventoryItem $item, ItemMovementsQuery $query, CsvExporter $csv): StreamedResponse
    {
        $rows = $query->get($item, 100000);

        return $csv->download(
            ($item->sku !== '' ? $item->sku : $item->getKey()).'-movements-'.now()->toDateString().'.csv',
            ['Date', 'Type', 'Quantity', 'Balance', 'Reference', 'Note', 'By'],
            array_map(fn (array $row): array => [
                $row['created_at'],
                $row['type'],
                $row['quantity'],
                $row['balance'],
                $row['reference'],
                $row['note'],
                $row['created_by']['name'] ?? null,
            ], $rows),
        );
    }

    /**
     * Inventory analytics (Figma 382:1592) — the same InventoryAnalyticsQuery
     * that backs the API's analytics endpoint and the dashboard's stock tiles.
     */
    public function analytics(Request $request, InventoryAnalyticsQuery $query): Response
    {
        // The "In and out" card's range picker (Figma 382:1592). An
        // unrecognised value falls back to the design's own default rather
        // than passing an arbitrary month count straight to the query.
        $months = (int) $request->query('months', 12);
        $months = in_array($months, [3, 6, 12], true) ? $months : 12;

        return Inertia::render('Inventory/Analytics', [
            'analytics' => $query->get(months: $months),
            'filters' => ['months' => $months],
        ]);
    }

    /**
     * Inventory check (Figma 271:12639) — the count sheet, grouped the way the
     * design groups it: a card per category, then bands per group/subcategory.
     */
    public function check(InventoryItemPresenter $presenter): Response
    {
        $items = InventoryItem::query()
            ->where('is_active', true)
            ->orderBy('category')
            ->orderBy('group')
            ->orderBy('subcategory')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        return Inertia::render('Inventory/Check', [
            'items' => $items->map(fn (InventoryItem $item) => $presenter->item($item))->values()->all(),
            // Past stocktakes for the design's "History" affordance.
            'history' => InventoryCheck::query()
                ->with('performedBy')
                ->orderByDesc('created_at')
                ->limit(10)
                ->get()
                ->map(fn (InventoryCheck $check) => InventoryCheckData::fromModel($check)->toArray())
                ->all(),
        ]);
    }

    /**
     * Apply the count sheet. The action writes reconciliation ADJUSTMENT
     * movements against the server's live stock, so a stale sheet cannot
     * overwrite a number that moved while it was open.
     */
    public function applyCheck(InventoryCheckRequest $request, ApplyInventoryCheckAction $action): RedirectResponse
    {
        /** @var list<array{item_id: string, physical_count: string}> $counts */
        $counts = $request->validated()['items'];

        $results = $action->execute($counts, $request->user());
        $adjusted = count(array_filter($results, fn (array $r) => $r['difference'] !== '0'));

        return back()->with(
            'success',
            $adjusted === 0
                ? __('Count matched the system — nothing to adjust.')
                : trans_choice(':count item adjusted|:count items adjusted', $adjusted, ['count' => $adjusted]),
        );
    }

    /**
     * Inventory spend (Figma 386:1673) — capital tied up against what actually
     * left, per product. Same InventorySpendQuery as the API endpoint.
     */
    public function spend(
        Request $request,
        InventorySpendQuery $query,
        InventoryAnalyticsQuery $analytics,
        OrderStockReconciliationQuery $reconciliation,
    ): Response {
        $preset = $request->query('preset');
        $from = $request->query('from');
        $to = $request->query('to');

        // The design frames this window as "90 days"; an explicit range wins.
        [$start, $end] = Period::resolve(
            is_string($preset) && $preset !== '' ? $preset : '90d',
            is_string($from) ? $from : null,
            is_string($to) ? $to : null,
        );

        // "Capital tied up" and "sitting untouched" are portfolio figures, not
        // window figures, so they come from the analytics query — resolved once,
        // since it is an expensive multi-aggregate read.
        $portfolio = $analytics->get();

        return Inertia::render('Inventory/Spend', [
            'spend' => $query->get($start, $end),
            'portfolio' => [
                'value' => $portfolio['value'],
                'summary' => $portfolio['summary'],
            ],
            'filters' => ['preset' => is_string($preset) && $preset !== '' ? $preset : '90d'],
            // "Check order → stock link" — a handful of extra joins over the
            // same window, so withheld until the panel actually opens.
            'reconciliation' => Inertia::optional(fn (): array => $reconciliation->get($start, $end)),
        ]);
    }

    /**
     * Inventory spend export (Figma 386:1673's "Export") — a CSV of the same
     * per-product rows the Spend table shows, over the same window.
     */
    public function exportSpend(Request $request, InventorySpendQuery $query, CsvExporter $csv): StreamedResponse
    {
        $preset = $request->query('preset');
        $from = $request->query('from');
        $to = $request->query('to');

        [$start, $end] = Period::resolve(
            is_string($preset) && $preset !== '' ? $preset : '90d',
            is_string($from) ? $from : null,
            is_string($to) ? $to : null,
        );

        $rows = $query->get($start, $end)['per_product'];

        return $csv->download(
            'inventory-spend-'.now()->toDateString().'.csv',
            [
                'Name', 'SKU', 'Vintage', 'Group', 'Subcategory', 'On Hand',
                'Stock Value', 'Units Exited', 'Velocity/day', 'Days Left',
                'Cost of Exits', 'Revenue',
            ],
            array_map(fn (array $row): array => [
                $row['name'],
                $row['sku'],
                $row['vintage'],
                $row['group'],
                $row['subcategory'],
                $row['on_hand'],
                $row['stock_value']['formatted'] ?? null,
                $row['units_exited'],
                $row['velocity_per_day'],
                $row['days_left'],
                $row['cost_of_exits']['formatted'] ?? null,
                $row['revenue']['formatted'] ?? null,
            ], $rows),
        );
    }

    /**
     * Bulk edit (Figma 270:9646) — the inventory list in an editable mode
     * rather than a separate route, which is how the design frames it.
     *
     * The design also shows the Stock column as editable. It is NOT editable
     * here: stock is derived from the movement ledger, so writing it directly
     * would leave the running balance and movement history disagreeing with the
     * item. Reconciling a physical count is what the Inventory Check screen is
     * for, and it records proper reconciliation movements.
     */
    public function bulkUpdate(
        BulkUpdateInventoryItemsRequest $request,
        BulkUpdateInventoryItemsAction $action,
    ): RedirectResponse {
        /** @var list<array<string, mixed>> $items */
        $items = $request->validated()['items'];

        $updated = $action->execute($items);

        return back()->with('success', trans_choice(':count item updated|:count items updated', $updated, [
            'count' => $updated,
        ]));
    }

    /**
     * Quick stock entry (Figma 449:1577) — the same AdjustStockAction the API's
     * adjust endpoint calls, so a correction means the same thing either way.
     */
    public function adjustStock(
        AdjustStockRequest $request,
        InventoryItem $item,
        AdjustStockAction $action,
    ): RedirectResponse {
        $action->execute(
            $item,
            StockMovementType::from($request->string('type')->value()),
            (string) $request->validated('quantity'),
            $request->has('reference') ? $request->string('reference')->value() : null,
            $request->has('note') ? $request->string('note')->value() : null,
            $request->boolean('is_reconciliation'),
        );

        return back()->with('success', __('Stock movement recorded.'));
    }

    /**
     * Replace an item's bill of materials (Product Detail · Recipe tab) — the
     * same SetRecipeAction and validation the JSON API's
     * PUT inventory-items/{item}/recipe uses.
     */
    public function updateRecipe(SetRecipeRequest $request, InventoryItem $item, SetRecipeAction $action): RedirectResponse
    {
        /** @var list<array{input_id: string, quantity: string}> $lines */
        $lines = array_values(array_map(
            fn (array $line): array => [
                'input_id' => (string) $line['input_id'],
                'quantity' => (string) $line['quantity'],
            ],
            (array) $request->validated('items', []),
        ));

        $action->execute($item, $lines);

        return back()->with('success', __('Recipe saved.'));
    }

    /**
     * Run a production batch off an item's recipe (Product Detail · Produce
     * tab) — the same ProduceItemAction the JSON API's
     * POST inventory-items/{item}/produce uses: consumes each input, adds the
     * output, in one transaction.
     */
    public function produce(ProduceItemRequest $request, InventoryItem $item, ProduceItemAction $action): RedirectResponse
    {
        $action->execute($item, (string) $request->validated('display_quantity'));

        return back()->with('success', __('Production recorded.'));
    }

    /**
     * Bulk Import (Inventory / Analytics / Spend's shared "Bulk Import"
     * button) — one CSV, matched by SKU against the catalog. A full page
     * reload follows (no `only`), which is what we want here: the list,
     * the analytics figures and anything the import touched should all
     * come back fresh, unlike the drawer-preserving partial reloads every
     * other write on these pages uses.
     */
    public function bulkImport(BulkImportInventoryItemsRequest $request, BulkImportInventoryItemsAction $action): RedirectResponse
    {
        /** @var UploadedFile $file */
        $file = $request->file('file');
        $result = $action->execute($file);

        $summary = trans_choice(':count item created|:count items created', $result['created'])
            .' · '.trans_choice(':count item updated|:count items updated', $result['updated']);

        if ($result['skipped'] === []) {
            return back()->with('success', $summary.'.');
        }

        $reasons = collect($result['skipped'])
            ->take(5)
            ->map(fn (array $s): string => __('row :row: :reason', $s))
            ->implode('; ');
        $extra = count($result['skipped']) > 5
            ? __(' and :count more', ['count' => count($result['skipped']) - 5])
            : '';

        return back()->with(
            $result['created'] + $result['updated'] > 0 ? 'success' : 'error',
            $summary.'. '.trans_choice(':count row skipped|:count rows skipped', count($result['skipped'])).': '.$reasons.$extra,
        );
    }

    /** The Bulk Import dialog's "Download template" — the columns it reads, with one example row. */
    public function bulkImportTemplate(CsvExporter $csv): StreamedResponse
    {
        return $csv->download('inventory-import-template.csv', BulkImportInventoryItemsAction::COLUMNS, [
            ['Plavac Mali 2022', 'PLAVAC-2022', 'FINISHED', 'Wine', 'bottles', '120', '24', '6', '4.50', '12.00'],
        ]);
    }

    public function store(StoreInventoryItemRequest $request, CreateInventoryItemAction $action): RedirectResponse
    {
        $data = $action->execute($request->validated());

        return redirect('/inventory/'.$data->id)->with('success', __('Item created.'));
    }

    public function update(
        UpdateInventoryItemRequest $request,
        InventoryItem $item,
        UpdateInventoryItemAction $action,
    ): RedirectResponse {
        $action->execute($item, $request->validated());

        return back()->with('success', __('Item updated.'));
    }

    /**
     * Clone an item (Figma 449:1577's "Duplicate") — the same
     * DuplicateInventoryItemAction the API's duplicate endpoint calls: new SKU,
     * " (Copy)" name, zero stock, recipe copied.
     */
    public function duplicate(InventoryItem $item, DuplicateInventoryItemAction $action): RedirectResponse
    {
        $copy = $action->execute($item);

        return redirect('/inventory/'.$copy->id)->with('success', __('Item duplicated.'));
    }

    public function destroy(InventoryItem $item, DeleteInventoryItemAction $action): RedirectResponse
    {
        $deactivated = $action->execute($item);

        return redirect('/inventory')->with(
            'success',
            $deactivated
                ? __('Item is referenced by orders and was deactivated instead of deleted.')
                : __('Item deleted.'),
        );
    }
}
