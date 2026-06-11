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
     * Tier price book for an item (tier → absolute price).
     */
    public function itemTierPrices(InventoryItem $item): JsonResponse
    {
        $rows = TierPrice::query()
            ->where('inventory_item_id', $item->getKey())
            ->with('pricingTier')
            ->get()
            ->map(fn (TierPrice $tp) => [
                'pricing_tier_id' => $tp->pricing_tier_id,
                'tier_name' => $tp->pricingTier?->name,
                'rebate_percent' => $tp->pricingTier?->rebate_percent,
                'price' => $tp->price->jsonSerialize(),
            ])
            ->all();

        return response()->json(['data' => $rows]);
    }

    /**
     * Customer-specific price overrides for an item (customer → absolute price).
     */
    public function itemCustomerPrices(InventoryItem $item): JsonResponse
    {
        $rows = CustomerPrice::query()
            ->where('inventory_item_id', $item->getKey())
            ->with('customer')
            ->get()
            ->map(fn (CustomerPrice $cp) => [
                'customer_id' => $cp->customer_id,
                'company_name' => $cp->customer?->company_name,
                'price' => $cp->price->jsonSerialize(),
            ])
            ->all();

        return response()->json(['data' => $rows]);
    }

    /**
     * The customer's negotiated per-product prices (absolute; override rebate).
     */
    public function customerPrices(Customer $customer): JsonResponse
    {
        $rows = CustomerPrice::query()
            ->where('customer_id', $customer->getKey())
            ->with('inventoryItem')
            ->get()
            ->map(fn (CustomerPrice $cp) => [
                'inventory_item_id' => $cp->inventory_item_id,
                'name' => $cp->inventoryItem?->name,
                'sku' => $cp->inventoryItem?->sku,
                'price' => $cp->price->jsonSerialize(),
            ])
            ->all();

        return response()->json(['data' => $rows]);
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
