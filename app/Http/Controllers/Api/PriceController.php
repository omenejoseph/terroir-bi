<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\Pricing\UpsertCustomerPriceAction;
use App\Actions\Pricing\UpsertTierPriceAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Pricing\UpsertPriceRequest;
use App\Models\Customer;
use App\Models\CustomerPrice;
use App\Models\InventoryItem;
use App\Models\PricingTier;
use App\Models\TierPrice;
use App\Services\Pricing\PricingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PriceController extends Controller
{
    public function upsertTierPrice(
        UpsertPriceRequest $request,
        InventoryItem $item,
        PricingTier $tier,
        UpsertTierPriceAction $action,
    ): JsonResponse {
        $tierPrice = $action->execute($item, $tier, (int) $request->validated('price'));

        return response()->json(['data' => $tierPrice->price->jsonSerialize()]);
    }

    public function destroyTierPrice(InventoryItem $item, PricingTier $tier): JsonResponse
    {
        TierPrice::query()
            ->where('inventory_item_id', $item->getKey())
            ->where('pricing_tier_id', $tier->getKey())
            ->delete();

        return response()->json(status: 204);
    }

    public function upsertCustomerPrice(
        UpsertPriceRequest $request,
        InventoryItem $item,
        Customer $customer,
        UpsertCustomerPriceAction $action,
    ): JsonResponse {
        $customerPrice = $action->execute($item, $customer, (int) $request->validated('price'));

        return response()->json(['data' => $customerPrice->price->jsonSerialize()]);
    }

    public function destroyCustomerPrice(InventoryItem $item, Customer $customer): JsonResponse
    {
        CustomerPrice::query()
            ->where('inventory_item_id', $item->getKey())
            ->where('customer_id', $customer->getKey())
            ->delete();

        return response()->json(status: 204);
    }

    /**
     * Batch price resolution for a customer (pricing engine).
     */
    public function resolvedPrices(Request $request, Customer $customer, PricingService $pricing): JsonResponse
    {
        $ids = array_filter(explode(',', (string) $request->query('item_ids', '')));

        $items = InventoryItem::query()->whereIn('id', $ids)->get();

        $resolved = [];
        foreach ($pricing->resolveForCustomer($customer, $items) as $itemId => $money) {
            $resolved[$itemId] = $money->jsonSerialize();
        }

        return response()->json(['data' => $resolved]);
    }
}
