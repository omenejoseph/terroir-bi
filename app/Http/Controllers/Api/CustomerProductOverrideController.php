<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\Customers\UpsertProductOverrideAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Customers\UpsertProductOverrideRequest;
use App\Models\Customer;
use App\Models\CustomerProductOverride;
use App\Models\InventoryItem;
use Illuminate\Http\JsonResponse;

class CustomerProductOverrideController extends Controller
{
    public function index(Customer $customer): JsonResponse
    {
        $overrides = $customer->productOverrides()->get()
            ->map(fn (CustomerProductOverride $o) => [
                'inventory_item_id' => $o->inventory_item_id,
                'visible' => $o->visible,
            ])
            ->all();

        return response()->json(['data' => $overrides]);
    }

    public function upsert(
        UpsertProductOverrideRequest $request,
        Customer $customer,
        InventoryItem $item,
        UpsertProductOverrideAction $action,
    ): JsonResponse {
        $override = $action->execute($customer, $item, $request->boolean('visible'));

        return response()->json(['data' => [
            'inventory_item_id' => $override->inventory_item_id,
            'visible' => $override->visible,
        ]]);
    }

    public function destroy(Customer $customer, InventoryItem $item): JsonResponse
    {
        $customer->productOverrides()
            ->where('inventory_item_id', $item->getKey())
            ->delete();

        return response()->json(status: 204);
    }
}
