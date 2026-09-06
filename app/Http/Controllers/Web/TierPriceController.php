<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Actions\Pricing\UpsertTierPriceAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Pricing\UpsertPriceRequest;
use App\Models\InventoryItem;
use App\Models\PricingTier;
use App\Models\TierPrice;
use Illuminate\Http\RedirectResponse;

/**
 * A pricing tier's absolute price for one item (Product Detail · Pricing tab,
 * Figma 449:1577). The same UpsertTierPriceAction and validation the JSON
 * API's PUT inventory-items/{item}/tier-price/{tier} uses, so the two
 * transports can't disagree about what a valid price is — see
 * Web\CustomerPriceController, which does the same thing for customer
 * overrides on the same tab.
 */
class TierPriceController extends Controller
{
    public function update(
        UpsertPriceRequest $request,
        InventoryItem $item,
        PricingTier $tier,
        UpsertTierPriceAction $action,
    ): RedirectResponse {
        $action->execute($item, $tier, (int) $request->validated('price'));

        return back()->with('success', __('Price saved.'));
    }

    public function destroy(InventoryItem $item, PricingTier $tier): RedirectResponse
    {
        TierPrice::query()
            ->where('inventory_item_id', $item->getKey())
            ->where('pricing_tier_id', $tier->getKey())
            ->delete();

        return back()->with('success', __('Price removed.'));
    }
}
