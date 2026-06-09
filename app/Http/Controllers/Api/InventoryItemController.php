<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\Inventory\CreateInventoryItemAction;
use App\Actions\Inventory\UpdateInventoryItemAction;
use App\DataTransferObjects\InventoryItemData;
use App\Http\Controllers\Controller;
use App\Http\Requests\Inventory\StoreInventoryItemRequest;
use App\Http\Requests\Inventory\UpdateInventoryItemRequest;
use App\Models\InventoryItem;
use App\Queries\InventoryAnalyticsQuery;
use App\Queries\InventoryTaxonomyQuery;
use App\Queries\ListInventoryItemsQuery;
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

    public function index(Request $request, ListInventoryItemsQuery $query): JsonResponse
    {
        $paginator = $query->paginate([
            'search' => $request->query('search'),
            'category' => $request->query('category'),
            'is_active' => $request->has('is_active') ? $request->boolean('is_active') : null,
            'is_for_sale' => $request->has('is_for_sale') ? $request->boolean('is_for_sale') : null,
            'sellable' => $request->boolean('sellable'),
        ]);

        return response()->json([
            'data' => array_map(
                fn (InventoryItem $item) => InventoryItemData::fromModel($item)->toArray(),
                $paginator->items(),
            ),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ]);
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

    public function destroy(InventoryItem $item): JsonResponse
    {
        // No orders yet; hard delete (cascades recipe lines, movements). The
        // soft-delete-when-referenced rule lands with the Orders module.
        $item->delete();

        return response()->json(status: 204);
    }
}
