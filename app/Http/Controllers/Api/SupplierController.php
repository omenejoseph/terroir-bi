<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\Suppliers\CreateSupplierAction;
use App\Actions\Suppliers\UpdateSupplierAction;
use App\Actions\Suppliers\UpsertSupplierPriceItemAction;
use App\DataTransferObjects\SupplierData;
use App\Http\Controllers\Controller;
use App\Http\Requests\Suppliers\StorePriceItemRequest;
use App\Http\Requests\Suppliers\StoreSupplierRequest;
use App\Http\Requests\Suppliers\UpdateSupplierRequest;
use App\Models\Supplier;
use App\Models\SupplierPriceItem;
use App\Queries\ListSuppliersQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SupplierController extends Controller
{
    public function index(Request $request, ListSuppliersQuery $query): JsonResponse
    {
        $paginator = $query->paginate([
            'search' => $request->query('search'),
            'is_active' => $request->has('is_active') ? $request->boolean('is_active') : null,
        ]);

        return response()->json([
            'data' => array_map(fn (Supplier $s) => SupplierData::fromModel($s)->toArray(), $paginator->items()),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }

    public function show(Supplier $supplier): JsonResponse
    {
        $supplier->loadMissing('priceItems')->loadCount('priceItems');

        return response()->json(['data' => SupplierData::fromModel($supplier)->toArray()]);
    }

    public function store(StoreSupplierRequest $request, CreateSupplierAction $action): JsonResponse
    {
        return response()->json(['data' => $action->execute($request->validated())->toArray()], 201);
    }

    public function update(UpdateSupplierRequest $request, Supplier $supplier, UpdateSupplierAction $action): JsonResponse
    {
        return response()->json(['data' => $action->execute($supplier, $request->validated())->toArray()]);
    }

    public function destroy(Supplier $supplier): JsonResponse
    {
        $supplier->delete();

        return response()->json(status: 204);
    }

    public function addPriceItem(StorePriceItemRequest $request, Supplier $supplier, UpsertSupplierPriceItemAction $action): JsonResponse
    {
        /** @var array{description: string, unit_price: int, unit?: ?string, notes?: ?string, inventory_item_id?: ?string} $attributes */
        $attributes = $request->validated();
        $item = $action->execute($supplier, $attributes);

        return response()->json(['data' => $this->priceItem($item)], 201);
    }

    public function deletePriceItem(Supplier $supplier, SupplierPriceItem $priceItem): JsonResponse
    {
        abort_unless($priceItem->supplier_id === $supplier->getKey(), 404);
        $priceItem->delete();

        return response()->json(status: 204);
    }

    /**
     * @return array<string, mixed>
     */
    private function priceItem(SupplierPriceItem $item): array
    {
        return [
            'id' => $item->getKey(),
            'inventory_item_id' => $item->inventory_item_id,
            'description' => $item->description,
            'unit_price' => $item->unit_price->jsonSerialize(),
            'unit' => $item->unit,
            'notes' => $item->notes,
            'last_updated' => $item->last_updated?->toIso8601String(),
        ];
    }
}
