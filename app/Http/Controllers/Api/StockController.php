<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\Inventory\AdjustStockAction;
use App\Actions\Inventory\ApplyInventoryCheckAction;
use App\Actions\Inventory\ProduceItemAction;
use App\Actions\Inventory\SetRecipeAction;
use App\DataTransferObjects\RecipeLineData;
use App\DataTransferObjects\StockMovementData;
use App\Enums\StockMovementType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Inventory\AdjustStockRequest;
use App\Http\Requests\Inventory\InventoryCheckRequest;
use App\Http\Requests\Inventory\ProduceItemRequest;
use App\Http\Requests\Inventory\SetRecipeRequest;
use App\Http\Requests\Inventory\SetReconciliationRequest;
use App\Models\InventoryItem;
use App\Models\RecipeItem;
use App\Models\StockMovement;
use Illuminate\Http\JsonResponse;

class StockController extends Controller
{
    /** Recent ledger entries for an item, newest first. ULIDs sort by creation. */
    public function movements(InventoryItem $item): JsonResponse
    {
        $movements = $item->stockMovements()->orderByDesc('id')->limit(100)->get();

        return response()->json([
            'data' => $movements
                ->map(fn (StockMovement $movement) => StockMovementData::fromModel($movement)->toArray())
                ->all(),
        ]);
    }

    /** The item's recipe (bill of materials), with input display fields resolved. */
    public function recipe(InventoryItem $item): JsonResponse
    {
        $lines = $item->recipe()->with('input')->get();

        return response()->json([
            'data' => $lines
                ->map(fn (RecipeItem $line) => RecipeLineData::fromModel($line)->toArray())
                ->all(),
        ]);
    }

    public function adjust(
        AdjustStockRequest $request,
        InventoryItem $item,
        AdjustStockAction $action,
    ): JsonResponse {
        $data = $action->execute(
            $item,
            StockMovementType::from($request->string('type')->value()),
            (string) $request->validated('quantity'),
            $request->has('reference') ? $request->string('reference')->value() : null,
            $request->has('note') ? $request->string('note')->value() : null,
            $request->boolean('is_reconciliation'),
        );

        return response()->json(['data' => $data->toArray()]);
    }

    /** Produce finished goods from the item's recipe (consumes inputs). */
    public function produce(
        ProduceItemRequest $request,
        InventoryItem $item,
        ProduceItemAction $action,
    ): JsonResponse {
        $data = $action->execute($item, (string) $request->validated('display_quantity'));

        return response()->json(['data' => $data->toArray()]);
    }

    /** Apply a physical stocktake → reconciliation ADJUSTMENT movements. */
    public function check(InventoryCheckRequest $request, ApplyInventoryCheckAction $action): JsonResponse
    {
        /** @var list<array{item_id: string, physical_count: string}> $counts */
        $counts = array_values(array_map(
            fn (array $line) => [
                'item_id' => (string) $line['item_id'],
                'physical_count' => (string) $line['physical_count'],
            ],
            (array) $request->validated('items', []),
        ));

        return response()->json(['data' => $action->execute($counts)]);
    }

    /** Flip a movement's reconciliation tag (no stock change). */
    public function setReconciliation(
        SetReconciliationRequest $request,
        StockMovement $stockMovement,
    ): JsonResponse {
        $stockMovement->update(['is_reconciliation' => $request->boolean('is_reconciliation')]);

        return response()->json(['data' => StockMovementData::fromModel($stockMovement->refresh())->toArray()]);
    }

    public function setRecipe(
        SetRecipeRequest $request,
        InventoryItem $item,
        SetRecipeAction $action,
    ): JsonResponse {
        /** @var list<array{input_id: string, quantity: string}> $lines */
        $lines = array_values(array_map(
            fn (array $line) => [
                'input_id' => (string) $line['input_id'],
                'quantity' => (string) $line['quantity'],
            ],
            (array) $request->validated('items', []),
        ));

        return response()->json(['data' => $action->execute($item, $lines)]);
    }
}
