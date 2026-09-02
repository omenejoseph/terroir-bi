<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Actions\Inventory\CreateInventoryItemAction;
use App\Actions\Inventory\DeleteInventoryItemAction;
use App\Actions\Inventory\UpdateInventoryItemAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Inventory\StoreInventoryItemRequest;
use App\Http\Requests\Inventory\UpdateInventoryItemRequest;
use App\Models\InventoryItem;
use App\Queries\InventoryAttentionQuery;
use App\Queries\InventoryTaxonomyQuery;
use App\Queries\ListInventoryItemsQuery;
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

    public function show(InventoryItem $item, InventoryItemPresenter $presenter): Response
    {
        $item->loadMissing('firstImage');

        return Inertia::render('Inventory/Show', [
            'item' => $presenter->item($item),
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
