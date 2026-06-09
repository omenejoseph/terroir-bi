<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\Suppliers\CreateSupplierOrderAction;
use App\Actions\Suppliers\DeleteSupplierOrderAction;
use App\Actions\Suppliers\UpdateSupplierOrderStatusAction;
use App\DataTransferObjects\SupplierOrderData;
use App\Enums\SupplierOrderStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Suppliers\StoreSupplierOrderRequest;
use App\Http\Requests\Suppliers\UpdateSupplierOrderStatusRequest;
use App\Models\Supplier;
use App\Models\SupplierOrder;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SupplierOrderController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $status = $request->query('status');
        $supplierId = $request->query('supplier_id');

        $paginator = SupplierOrder::query()
            ->with('supplier')
            ->when(is_string($status) && $status !== '', fn (Builder $q) => $q->where('status', SupplierOrderStatus::from((string) $status)))
            ->when(is_string($supplierId) && $supplierId !== '', fn (Builder $q) => $q->where('supplier_id', $supplierId))
            ->orderByDesc('created_at')
            ->paginate(25);

        return response()->json([
            'data' => array_map(fn (SupplierOrder $o) => SupplierOrderData::fromModel($o)->toArray(), $paginator->items()),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }

    public function show(SupplierOrder $supplierOrder): JsonResponse
    {
        $supplierOrder->loadMissing(['supplier', 'items']);

        return response()->json(['data' => SupplierOrderData::fromModel($supplierOrder)->toArray()]);
    }

    public function store(StoreSupplierOrderRequest $request, CreateSupplierOrderAction $action): JsonResponse
    {
        $supplier = Supplier::query()->whereKey((string) $request->validated('supplier_id'))->firstOrFail();
        /** @var list<array<string, mixed>> $items */
        $items = (array) $request->validated('items', []);

        $order = $action->execute($supplier, $request->validated(), $items, $this->userId($request));

        return response()->json(['data' => SupplierOrderData::fromModel($order->load(['supplier', 'items']))->toArray()], 201);
    }

    public function updateStatus(UpdateSupplierOrderStatusRequest $request, SupplierOrder $supplierOrder, UpdateSupplierOrderStatusAction $action): JsonResponse
    {
        $order = $action->execute($supplierOrder, SupplierOrderStatus::from((string) $request->validated('status')));

        return response()->json(['data' => SupplierOrderData::fromModel($order->load(['supplier', 'items']))->toArray()]);
    }

    public function destroy(SupplierOrder $supplierOrder, DeleteSupplierOrderAction $action): JsonResponse
    {
        $action->execute($supplierOrder);

        return response()->json(status: 204);
    }

    private function userId(Request $request): string
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        return $user->getKey();
    }
}
