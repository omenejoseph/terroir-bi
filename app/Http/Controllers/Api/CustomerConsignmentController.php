<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\Orders\CreateOrderAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Orders\CustomerConsignmentReturnRequest;
use App\Http\Requests\Orders\CustomerConsignmentSaleRequest;
use App\Http\Requests\Orders\PlaceConsignmentRequest;
use App\Models\Customer;
use App\Models\User;
use App\Services\Orders\CustomerConsignmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Customer-level consignment: one rollup across all of a customer's placements,
 * with FIFO sale/return allocation (oldest placement first).
 */
class CustomerConsignmentController extends Controller
{
    public function summary(Customer $customer, CustomerConsignmentService $service): JsonResponse
    {
        return response()->json(['data' => $service->summary($customer)]);
    }

    public function place(PlaceConsignmentRequest $request, Customer $customer, CreateOrderAction $action): JsonResponse
    {
        /** @var list<array<string, mixed>> $items */
        $items = (array) $request->validated('items', []);

        $order = $action->execute($customer, $this->userId($request), [
            'is_consignment' => true,
            'notes' => $request->validated('note'),
            'items' => $items,
        ]);

        return response()->json(['data' => ['order_number' => $order->order_number]], 201);
    }

    public function sale(CustomerConsignmentSaleRequest $request, Customer $customer, CustomerConsignmentService $service): JsonResponse
    {
        /** @var list<array{inventory_item_id: string, quantity: int|string, unit_price?: int|string|null}> $items */
        $items = (array) $request->validated('items', []);
        $service->sale($customer, $items, $this->note($request), $this->userId($request));

        return response()->json(['data' => $service->summary($customer)]);
    }

    public function recordReturn(CustomerConsignmentReturnRequest $request, Customer $customer, CustomerConsignmentService $service): JsonResponse
    {
        /** @var list<array{inventory_item_id: string, quantity: int|string}> $items */
        $items = (array) $request->validated('items', []);
        $service->return($customer, $items, $this->note($request), $this->userId($request));

        return response()->json(['data' => $service->summary($customer)]);
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
