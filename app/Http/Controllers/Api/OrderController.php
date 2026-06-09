<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\Orders\AddOrderItemsAction;
use App\Actions\Orders\CreateOrderAction;
use App\Actions\Orders\DeleteOrderAction;
use App\Actions\Orders\DeleteOrderItemAction;
use App\Actions\Orders\UpdateOrderBackorderAction;
use App\Actions\Orders\UpdateOrderItemAction;
use App\Actions\Orders\UpdateOrderItemCostAction;
use App\Actions\Orders\UpdateOrderNotesAction;
use App\Actions\Orders\UpdateOrderShippingAction;
use App\Actions\Orders\UpdateOrderStatusAction;
use App\Authorization\MembershipContext;
use App\DataTransferObjects\OrderData;
use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Orders\AddOrderItemsRequest;
use App\Http\Requests\Orders\StoreOrderRequest;
use App\Http\Requests\Orders\UpdateOrderBackorderRequest;
use App\Http\Requests\Orders\UpdateOrderItemCostRequest;
use App\Http\Requests\Orders\UpdateOrderItemRequest;
use App\Http\Requests\Orders\UpdateOrderNotesRequest;
use App\Http\Requests\Orders\UpdateOrderShippingRequest;
use App\Http\Requests\Orders\UpdateOrderStatusRequest;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\User;
use App\Queries\ListOrdersQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OrderController extends Controller
{
    public function __construct(private readonly MembershipContext $membership) {}

    public function index(Request $request, ListOrdersQuery $query): JsonResponse
    {
        $paginator = $query->paginate([
            'status' => $request->query('status'),
            'search' => $request->query('search'),
            'hide_shipped' => ! $this->membership->canSeeShippedOrders(),
        ]);

        return response()->json([
            'data' => array_map(
                fn (Order $order) => $this->present($order),
                $paginator->items(),
            ),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }

    public function show(Order $order): JsonResponse
    {
        return response()->json(['data' => $this->present($order)]);
    }

    public function store(StoreOrderRequest $request, CreateOrderAction $action): JsonResponse
    {
        $customer = Customer::query()->whereKey((string) $request->validated('customer_id'))->firstOrFail();
        $order = $action->execute($customer, $this->userId($request), $request->validated());

        return response()->json(['data' => $this->present($order)], 201);
    }

    public function updateStatus(UpdateOrderStatusRequest $request, Order $order, UpdateOrderStatusAction $action): JsonResponse
    {
        $action->execute(
            $order,
            OrderStatus::from((string) $request->validated('status')),
            $request->has('note') ? $request->string('note')->value() : null,
            $this->userId($request),
        );

        return response()->json(['data' => $this->present($order)]);
    }

    public function addItems(AddOrderItemsRequest $request, Order $order, AddOrderItemsAction $action): JsonResponse
    {
        /** @var list<array<string, mixed>> $items */
        $items = (array) $request->validated('items', []);
        $action->execute($order, $items);

        return response()->json(['data' => $this->present($order)]);
    }

    public function updateItem(UpdateOrderItemRequest $request, OrderItem $orderItem, UpdateOrderItemAction $action): JsonResponse
    {
        $action->execute(
            $orderItem,
            $request->has('quantity') ? (int) $request->validated('quantity') : null,
            $request->has('unit_type') ? (string) $request->validated('unit_type') : null,
        );

        return response()->json(['data' => $this->present($orderItem->order()->firstOrFail())]);
    }

    public function updateItemCost(UpdateOrderItemCostRequest $request, OrderItem $orderItem, UpdateOrderItemCostAction $action): JsonResponse
    {
        $cost = $request->validated('cost_per_unit');
        $action->execute($orderItem, $cost !== null ? (int) $cost : null);

        return response()->json(['data' => $this->present($orderItem->order()->firstOrFail())]);
    }

    public function deleteItem(OrderItem $orderItem, DeleteOrderItemAction $action): JsonResponse
    {
        $order = $orderItem->order()->firstOrFail();
        $action->execute($orderItem);

        return response()->json(['data' => $this->present($order->refresh())]);
    }

    public function updateShipping(UpdateOrderShippingRequest $request, Order $order, UpdateOrderShippingAction $action): JsonResponse
    {
        $shipping = $request->validated('shipping_cost');
        $action->execute(
            $order,
            $shipping !== null ? (int) $shipping : null,
            $request->has('shipping_paid_by_us') ? $request->boolean('shipping_paid_by_us') : null,
        );

        return response()->json(['data' => $this->present($order)]);
    }

    public function updateBackorder(UpdateOrderBackorderRequest $request, Order $order, UpdateOrderBackorderAction $action): JsonResponse
    {
        $date = $request->validated('backorder_date');
        $action->execute($order, $date !== null ? (string) $date : null);

        return response()->json(['data' => $this->present($order)]);
    }

    public function updateNotes(UpdateOrderNotesRequest $request, Order $order, UpdateOrderNotesAction $action): JsonResponse
    {
        $notes = $request->validated('notes');
        $action->execute($order, $notes !== null ? (string) $notes : null);

        return response()->json(['data' => $this->present($order)]);
    }

    public function destroy(Order $order, DeleteOrderAction $action): JsonResponse
    {
        $action->execute($order);

        return response()->json(status: 204);
    }

    private function present(Order $order): mixed
    {
        $order->loadMissing(['customer', 'createdBy', 'items.inventoryItem', 'statusHistories.changedBy', 'orderNotes.author']);

        return OrderData::fromModel($order, $this->membership->canSeeFinancials())->toArray();
    }

    private function userId(Request $request): string
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        return $user->getKey();
    }
}
