<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Actions\Orders\CreateOrderAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Orders\CustomerConsignmentReturnRequest;
use App\Http\Requests\Orders\CustomerConsignmentSaleRequest;
use App\Http\Requests\Orders\PlaceConsignmentRequest;
use App\Models\Customer;
use App\Models\User;
use App\Services\Orders\CustomerConsignmentService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Customer — Show · Consignment tab (Figma 231:9336's "Komisija"): place new
 * goods, and record sales/returns FIFO across the customer's open placements.
 * The same requests, action and CustomerConsignmentService the JSON API's
 * customers/{customer}/consignment/* endpoints already use — see
 * Api\CustomerConsignmentController — so the two transports can't disagree
 * about what a valid allocation is.
 */
class CustomerConsignmentController extends Controller
{
    public function place(PlaceConsignmentRequest $request, Customer $customer, CreateOrderAction $action): RedirectResponse
    {
        /** @var list<array<string, mixed>> $items */
        $items = (array) $request->validated('items', []);

        $action->execute($customer, $this->userId($request), [
            'is_consignment' => true,
            'notes' => $request->validated('note'),
            'items' => $items,
        ]);

        return back()->with('success', __('Goods placed on consignment.'));
    }

    public function sale(CustomerConsignmentSaleRequest $request, Customer $customer, CustomerConsignmentService $service): RedirectResponse
    {
        /** @var list<array{inventory_item_id: string, quantity: int|string, unit_price?: int|string|null}> $items */
        $items = (array) $request->validated('items', []);
        $service->sale($customer, $items, $this->note($request), $this->userId($request));

        return back()->with('success', __('Sale recorded.'));
    }

    public function recordReturn(CustomerConsignmentReturnRequest $request, Customer $customer, CustomerConsignmentService $service): RedirectResponse
    {
        /** @var list<array{inventory_item_id: string, quantity: int|string}> $items */
        $items = (array) $request->validated('items', []);
        $service->return($customer, $items, $this->note($request), $this->userId($request));

        return back()->with('success', __('Return recorded.'));
    }

    private function note(Request $request): ?string
    {
        return $request->has('note') ? $request->string('note')->value() : null;
    }

    private function userId(Request $request): string
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        return $user->getKey();
    }
}
