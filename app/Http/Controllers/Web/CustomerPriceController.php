<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Actions\Pricing\UpsertCustomerPriceAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Pricing\UpsertPriceRequest;
use App\Models\Customer;
use App\Models\CustomerPrice;
use App\Models\InventoryItem;
use Illuminate\Http\RedirectResponse;

/**
 * A customer's own negotiated price for one product (Customer — Show ·
 * Pricing tab, Figma 231:9336 — the tab exists in the design; this editing
 * surface does not, since the frame only shows the read-only list). The same
 * UpsertCustomerPriceAction and validation the JSON API's
 * PUT inventory-items/{item}/customer-price/{customer} uses, so the two
 * transports can't disagree about what a valid price is.
 */
class CustomerPriceController extends Controller
{
    public function update(
        UpsertPriceRequest $request,
        Customer $customer,
        InventoryItem $item,
        UpsertCustomerPriceAction $action,
    ): RedirectResponse {
        $action->execute($item, $customer, (int) $request->validated('price'));

        return back()->with('success', __('Price saved.'));
    }

    public function destroy(Customer $customer, InventoryItem $item): RedirectResponse
    {
        CustomerPrice::query()
            ->where('customer_id', $customer->getKey())
            ->where('inventory_item_id', $item->getKey())
            ->delete();

        return back()->with('success', __('Price removed.'));
    }
}
