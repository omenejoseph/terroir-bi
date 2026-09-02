<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Actions\Inventory\AdjustStockAction;
use App\Actions\Inventory\ApplyInventoryCheckAction;
use App\Actions\Inventory\BulkUpdateInventoryItemsAction;
use App\Actions\Inventory\CreateInventoryItemAction;
use App\Actions\Inventory\DeleteInventoryItemAction;
use App\Actions\Inventory\UpdateInventoryItemAction;
use App\DataTransferObjects\InventoryCheckData;
use App\Enums\StockMovementType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Inventory\AdjustStockRequest;
use App\Http\Requests\Inventory\BulkUpdateInventoryItemsRequest;
use App\Http\Requests\Inventory\InventoryCheckRequest;
use App\Http\Requests\Inventory\StoreInventoryItemRequest;
use App\Http\Requests\Inventory\UpdateInventoryItemRequest;
use App\Models\InventoryCheck;
use App\Models\InventoryItem;
use App\Queries\InventoryAnalyticsQuery;
use App\Queries\InventoryAttentionQuery;
use App\Queries\InventoryItemStockAnalyticsQuery;
use App\Queries\InventorySpendQuery;
use App\Queries\InventoryTaxonomyQuery;
use App\Queries\ItemMovementsQuery;
use App\Queries\ListInventoryItemsQuery;
use App\Queries\VintageCoverageQuery;
use App\Services\Inventory\InventoryItemPresenter;
use App\Support\InventoryItemFilters;
use App\Support\Period;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

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
    ): Response {
        $filters = InventoryItemFilters::fromRequest($request);

        return Inertia::render('Inventory/Index', [
            'items' => $presenter->page($query->paginate($filters)),
            'filters' => $filters,
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
                $id = $request->query('item');

                if (! is_string($id) || $id === '') {
                    return [];
                }

                $item = InventoryItem::query()->find($id);

                return $item === null ? [] : $movements->get($item, 5);
            }),
        ]);
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
        ]);
    }

    /**
     * Inventory analytics (Figma 382:1592) — the same InventoryAnalyticsQuery
     * that backs the API's analytics endpoint and the dashboard's stock tiles.
     */
    public function analytics(InventoryAnalyticsQuery $query): Response
    {
        return Inertia::render('Inventory/Analytics', [
            'analytics' => $query->get(),
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
    public function spend(Request $request, InventorySpendQuery $query, InventoryAnalyticsQuery $analytics): Response
    {
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
        ]);
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
