<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\Inventory\AdjustStockAction;
use App\Actions\Inventory\SetRecipeAction;
use App\Enums\StockMovementType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Inventory\AdjustStockRequest;
use App\Http\Requests\Inventory\SetRecipeRequest;
use App\Models\InventoryItem;
use Illuminate\Http\JsonResponse;

class StockController extends Controller
{
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
        );

        return response()->json(['data' => $data->toArray()]);
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
