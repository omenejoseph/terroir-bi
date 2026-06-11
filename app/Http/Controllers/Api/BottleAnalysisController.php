<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\DataTransferObjects\BottleAnalysisData;
use App\Http\Controllers\Controller;
use App\Http\Requests\Inventory\StoreBottleAnalysisRequest;
use App\Models\BottleAnalysis;
use App\Models\InventoryItem;
use Illuminate\Http\JsonResponse;

class BottleAnalysisController extends Controller
{
    /** Analyses for an item, newest first. */
    public function index(InventoryItem $item): JsonResponse
    {
        $rows = $item->bottleAnalyses()
            ->orderByDesc('analyzed_on')
            ->orderByDesc('id')
            ->get()
            ->map(fn (BottleAnalysis $a) => BottleAnalysisData::fromModel($a)->toArray())
            ->all();

        return response()->json(['data' => $rows]);
    }

    public function store(StoreBottleAnalysisRequest $request, InventoryItem $item): JsonResponse
    {
        $analysis = $item->bottleAnalyses()->create($request->validated());

        return response()->json(['data' => BottleAnalysisData::fromModel($analysis)->toArray()], 201);
    }

    public function destroy(InventoryItem $item, BottleAnalysis $analysis): JsonResponse
    {
        abort_unless($analysis->inventory_item_id === $item->getKey(), 404);

        $analysis->delete();

        return response()->json(status: 204);
    }
}
