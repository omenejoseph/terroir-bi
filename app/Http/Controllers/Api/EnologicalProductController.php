<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\Cellar\AdjustEnologicalStockAction;
use App\Actions\Cellar\CreateEnologicalProductAction;
use App\Actions\Cellar\DeleteEnologicalProductAction;
use App\Actions\Cellar\UpdateEnologicalProductAction;
use App\DataTransferObjects\EnologicalProductData;
use App\Http\Controllers\Controller;
use App\Http\Requests\Cellar\AdjustEnologicalStockRequest;
use App\Http\Requests\Cellar\StoreEnologicalProductRequest;
use App\Http\Requests\Cellar\UpdateEnologicalProductRequest;
use App\Models\EnologicalProduct;
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
        $product = $action->execute($enologicalProduct, (string) $request->validated('delta'));

        return response()->json(['data' => EnologicalProductData::fromModel($product)->toArray()]);
    }

    public function destroy(EnologicalProduct $enologicalProduct, DeleteEnologicalProductAction $action): JsonResponse
    {
        $action->execute($enologicalProduct);

        return response()->json(status: 204);
    }
}
