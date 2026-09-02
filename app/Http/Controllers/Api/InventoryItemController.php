<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\Inventory\BulkUpdateInventoryItemsAction;
use App\Actions\Inventory\CreateInventoryItemAction;
use App\Actions\Inventory\DeleteInventoryItemAction;
use App\Actions\Inventory\DuplicateInventoryItemAction;
use App\Actions\Inventory\UpdateInventoryItemAction;
use App\DataTransferObjects\InventoryItemData;
use App\Http\Controllers\Controller;
use App\Http\Requests\Inventory\BulkUpdateInventoryItemsRequest;
use App\Http\Requests\Inventory\StoreInventoryItemRequest;
use App\Http\Requests\Inventory\UpdateInventoryItemRequest;
use App\Models\InventoryItem;
use App\Queries\InventoryAnalyticsQuery;
use App\Queries\InventoryTaxonomyQuery;
use App\Queries\ListInventoryItemsQuery;
use App\Services\Inventory\InventoryItemPresenter;
use App\Support\InventoryItemFilters;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class InventoryItemController extends Controller
{
    /** Distinct category/group/subcategory combinations for autocomplete + grouping. */
    public function taxonomy(InventoryTaxonomyQuery $query): JsonResponse
    {
        return response()->json(['data' => $query->get()]);
    }

    /** Read-optimised analytics for the inventory charts (stock levels, value, low stock). */
    public function analytics(InventoryAnalyticsQuery $query): JsonResponse
    {
        return response()->json(['data' => $query->get()]);
    }

    public function index(Request $request, ListInventoryItemsQuery $query, InventoryItemPresenter $presenter): JsonResponse
    {
        $paginator = $query->paginate(InventoryItemFilters::fromRequest($request));

        return response()->json($presenter->page($paginator));
    }

    public function show(InventoryItem $item): JsonResponse
    {
        return response()->json(['data' => InventoryItemData::fromModel($item)->toArray()]);
    }

    public function store(StoreInventoryItemRequest $request, CreateInventoryItemAction $action): JsonResponse
    {
        $data = $action->execute($request->validated());

        return response()->json(['data' => $data->toArray()], 201);
    }

    public function update(
        UpdateInventoryItemRequest $request,
        InventoryItem $item,
        UpdateInventoryItemAction $action,
    ): JsonResponse {
        $data = $action->execute($item, $request->validated());

        return response()->json(['data' => $data->toArray()]);
    }

    /** Apply per-row edits to many items at once (bulk-edit grid). */
    public function bulkUpdate(BulkUpdateInventoryItemsRequest $request, BulkUpdateInventoryItemsAction $action): JsonResponse
    {
        /** @var list<array<string, mixed>> $items */
        $items = $request->validated()['items'];

        return response()->json(['data' => ['updated' => $action->execute($items)]]);
    }

    /** Clone an item (new SKU, " (Copy)" name, zero stock, recipe copied). */
    public function duplicate(InventoryItem $item, DuplicateInventoryItemAction $action): JsonResponse
    {
        $data = $action->execute($item);

        return response()->json(['data' => $data->toArray()], 201);
    }

    public function destroy(InventoryItem $item, DeleteInventoryItemAction $action): JsonResponse
    {
        if ($action->execute($item)) {
            return response()->json([
                'data' => InventoryItemData::fromModel($item)->toArray(),
                'message' => 'Item is referenced by orders and was deactivated instead of deleted.',
            ]);
        }

        return response()->json(status: 204);
    }
}
