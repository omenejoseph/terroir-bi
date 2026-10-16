<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\Cellar\AdjustEnologicalStockAction;
use App\Actions\Cellar\BulkUpdateEnologicalProductsAction;
use App\Actions\Cellar\CreateEnologicalProductAction;
use App\Actions\Cellar\DeleteEnologicalProductAction;
use App\Actions\Cellar\DraftEnologicalPurchaseOrdersAction;
use App\Actions\Cellar\ImportEnologicalProductsAction;
use App\Actions\Cellar\UpdateEnologicalProductAction;
use App\DataTransferObjects\EnologicalProductData;
use App\Http\Controllers\Controller;
use App\Http\Requests\Cellar\AdjustEnologicalStockRequest;
use App\Http\Requests\Cellar\BulkUpdateEnologicalProductsRequest;
use App\Http\Requests\Cellar\DraftEnologicalOrdersRequest;
use App\Http\Requests\Cellar\ImportEnologicalProductsRequest;
use App\Http\Requests\Cellar\StoreEnologicalProductRequest;
use App\Http\Requests\Cellar\UpdateEnologicalProductRequest;
use App\Models\EnologicalProduct;
use App\Models\EnologicalStockMovement;
use App\Models\User;
use App\Queries\ProtocolNeedsQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EnologicalProductController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $products = EnologicalProduct::query()
            ->when($request->boolean('active_only'), fn ($q) => $q->where('is_active', true))
            ->orderBy('name')
            ->get();

        return response()->json([
            'data' => $products->map(fn (EnologicalProduct $p) => EnologicalProductData::fromModel($p)->toArray())->all(),
        ]);
    }

    public function store(StoreEnologicalProductRequest $request, CreateEnologicalProductAction $action): JsonResponse
    {
        $product = $action->execute($request->validated());

        return response()->json(['data' => EnologicalProductData::fromModel($product)->toArray()], 201);
    }

    public function update(UpdateEnologicalProductRequest $request, EnologicalProduct $enologicalProduct, UpdateEnologicalProductAction $action): JsonResponse
    {
        $product = $action->execute($enologicalProduct, $request->validated());

        return response()->json(['data' => EnologicalProductData::fromModel($product)->toArray()]);
    }

    public function adjustStock(AdjustEnologicalStockRequest $request, EnologicalProduct $enologicalProduct, AdjustEnologicalStockAction $action): JsonResponse
    {
        $note = $request->validated('note');
        $product = $action->execute($enologicalProduct, (string) $request->validated('delta'), $note !== null ? (string) $note : null);

        return response()->json(['data' => EnologicalProductData::fromModel($product)->toArray()]);
    }

    public function destroy(EnologicalProduct $enologicalProduct, DeleteEnologicalProductAction $action): JsonResponse
    {
        $action->execute($enologicalProduct);

        return response()->json(status: 204);
    }

    public function movements(EnologicalProduct $enologicalProduct): JsonResponse
    {
        $rows = EnologicalStockMovement::query()
            ->with('createdBy')
            ->where('enological_product_id', $enologicalProduct->getKey())
            ->orderByDesc('created_at')
            ->orderByDesc('id') // ULIDs are monotonic: same-second movements stay in order
            ->limit(200)
            ->get();

        return response()->json(['data' => $rows->map(fn (EnologicalStockMovement $m): array => [
            'id' => $m->getKey(),
            'date' => $m->created_at?->toIso8601String(),
            'delta' => (string) $m->delta,
            'balance_after' => (string) $m->balance_after,
            'source' => $m->source->value,
            'reference_type' => $m->reference_type,
            'reference_id' => $m->reference_id,
            'note' => $m->note,
            'by' => $m->createdBy?->fullName(),
        ])->all()]);
    }

    public function needs(ProtocolNeedsQuery $query): JsonResponse
    {
        return response()->json(['data' => $query->get()]);
    }

    public function import(ImportEnologicalProductsRequest $request, ImportEnologicalProductsAction $action): JsonResponse
    {
        $data = $request->validated();
        $result = $action->execute((string) $data['text'], (bool) ($data['dry_run'] ?? false));

        return response()->json(['data' => $result], $result['errors'] === [] ? 200 : 422);
    }

    public function bulkUpdate(BulkUpdateEnologicalProductsRequest $request, BulkUpdateEnologicalProductsAction $action): JsonResponse
    {
        $data = $request->validated();

        /** @var list<string> $ids */
        $ids = array_values($data['ids']);

        return response()->json(['data' => ['updated' => $action->execute($ids, (array) $data['fields'])]]);
    }

    public function draftOrders(DraftEnologicalOrdersRequest $request, DraftEnologicalPurchaseOrdersAction $action): JsonResponse
    {
        $data = $request->validated();
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        /** @var list<array{enological_product_id: string, quantity: float|int|string}>|null $lines */
        $lines = isset($data['lines']) ? array_values($data['lines']) : null;

        return response()->json(['data' => $action->execute($lines, $user->getKey())], 201);
    }
}
