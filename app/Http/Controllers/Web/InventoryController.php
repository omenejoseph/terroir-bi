<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Actions\Inventory\AdjustStockAction;
use App\Actions\Inventory\BulkUpdateInventoryItemsAction;
use App\Actions\Inventory\CreateInventoryItemAction;
use App\Actions\Inventory\DeleteInventoryItemAction;
use App\Actions\Inventory\UpdateInventoryItemAction;
use App\Enums\StockMovementType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Inventory\AdjustStockRequest;
use App\Http\Requests\Inventory\BulkUpdateInventoryItemsRequest;
use App\Http\Requests\Inventory\StoreInventoryItemRequest;
use App\Http\Requests\Inventory\UpdateInventoryItemRequest;
use App\Models\InventoryItem;
use App\Queries\InventoryAnalyticsQuery;
use App\Queries\InventoryAttentionQuery;
use App\Queries\InventoryItemStockAnalyticsQuery;
use App\Queries\InventoryTaxonomyQuery;
use App\Queries\ItemMovementsQuery;
use App\Queries\ListInventoryItemsQuery;
use App\Queries\VintageCoverageQuery;
use App\Services\Inventory\InventoryItemPresenter;
use App\Support\InventoryItemFilters;
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
