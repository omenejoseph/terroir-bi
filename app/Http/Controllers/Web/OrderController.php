<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Actions\Orders\AddOrderCommentAction;
use App\Actions\Orders\AddOrderItemsAction;
use App\Actions\Orders\CreateOrderAction;
use App\Actions\Orders\DeleteOrderAction;
use App\Actions\Orders\DeleteOrderItemAction;
use App\Actions\Orders\DuplicateOrderAction;
use App\Actions\Orders\UpdateOrderItemAction;
use App\Actions\Orders\UpdateOrderNotesAction;
use App\Actions\Orders\UpdateOrderStatusAction;
use App\Authorization\MembershipContext;
use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Orders\AddOrderCommentRequest;
use App\Http\Requests\Orders\AddOrderItemsRequest;
use App\Http\Requests\Orders\StoreOrderRequest;
use App\Http\Requests\Orders\UpdateOrderItemRequest;
use App\Http\Requests\Orders\UpdateOrderNotesRequest;
use App\Http\Requests\Orders\UpdateOrderStatusRequest;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\User;
use App\Queries\ListOrdersQuery;
use App\Queries\OrderPipelineQuery;
use App\Queries\OrderStatusCountsQuery;
use App\Services\Orders\OrderFormOptions;
use App\Services\Orders\OrderPresenter;
use App\Support\OrderFilters;
use App\Support\Period;
use App\Support\PerPage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Inertia counterpart of Api\OrderController.
 *
 * Every read goes through the same Query + OrderPresenter and every write
 * through the same Action as the API, so the two transports share behaviour by
 * construction rather than by discipline. Only the envelope differs: page props
 * and redirects here, JSON there.
 *
 * The public token order page stays on routes/api.php. It is unauthenticated
 * and resolves its tenant from the token, so moving it onto session auth would
 * break it — see PublicOrderController.
 */
class OrderController extends Controller
{
    public function __construct(private readonly MembershipContext $membership) {}

    /**
     * Orders list (Figma 455:1577): period tabs, the order-to-cash pipeline
     * card, the toolbar, the status chips, then the table.
     */
    public function index(
        Request $request,
        ListOrdersQuery $query,
        OrderPresenter $presenter,
        OrderStatusCountsQuery $counts,
        OrderPipelineQuery $pipeline,
        OrderFormOptions $options,
    ): Response {
        $filters = OrderFilters::fromRequest($request);

        // An explicit from/to (the "Custom" tab) wins over the preset, which is
        // exactly what Period::resolve already does with its two arguments.
        [$from, $to] = Period::resolve(
            $filters['period'] ?? 'ytd',
            $filters['from'],
            $filters['to'],
        );

        // hide_shipped is a membership rule, not a user filter: a member without
        // can_see_shipped_orders must not be able to reach shipped orders by
        // editing the query string, so it is applied server-side on every read.
        $scoped = [
            ...$filters,
            'from' => $from->toDateTimeString(),
            'to' => $to->toDateTimeString(),
            'hide_shipped' => ! $this->membership->canSeeShippedOrders(),
        ];

        return Inertia::render('Orders/Index', [
            'orders' => $presenter->page($query->paginate($scoped, PerPage::fromRequest($request))),
            'filters' => $filters,
            // Chip counts describe the whole filtered set, so they stay stable
            // while you switch between statuses.
            'statusCounts' => $counts->get($scoped),
            // The pipeline card needs financial visibility to be meaningful —
            // it is denominated in money — so it is withheld rather than zeroed.
            'pipeline' => $this->membership->canSeeFinancials()
                ? $pipeline->get($from, $to)
                : null,
            // The Create Order drawer's two pickers. Both are whole-catalog
            // scans, so they are only paid for when the drawer opens.
            'customerOptions' => Inertia::optional(fn (): array => $options->customers()),
            'productOptions' => Inertia::optional(fn (): array => $options->products()),
            // The Order — View drawer (376:1592) pulls the open order's full
            // detail through a partial reload, so the list never pays for lines,
            // timeline and comments nobody opened.
            'order' => Inertia::optional(function () use ($request, $presenter): ?array {
                $id = $request->query('order');

                if (! is_string($id) || $id === '') {
                    return null;
                }

                $order = Order::query()->find($id);

                return $order === null ? null : $presenter->detail($order);
            }),
        ]);
    }

    public function store(StoreOrderRequest $request, CreateOrderAction $action): RedirectResponse
    {
        $customer = Customer::query()->whereKey((string) $request->validated('customer_id'))->firstOrFail();
        $order = $action->execute($customer, $this->userId($request), $request->validated());

        return redirect('/orders?order='.$order->getKey())->with('success', __('Order placed.'));
    }

    /**
     * The drawer's status stepper (376:1592) — tapping a step advances the
     * order through the same action the API's status endpoint calls, so the
     * stock and history side effects are identical.
     */
    public function updateStatus(
        UpdateOrderStatusRequest $request,
        Order $order,
        UpdateOrderStatusAction $action,
    ): RedirectResponse {
        $action->execute(
            $order,
            OrderStatus::from((string) $request->validated('status')),
            $request->has('note') ? $request->string('note')->value() : null,
            $this->userId($request),
        );

        return back()->with('success', __('Order moved to :status.', [
            'status' => OrderStatus::from((string) $request->validated('status'))->label(),
        ]));
    }

    public function updateNotes(
        UpdateOrderNotesRequest $request,
        Order $order,
        UpdateOrderNotesAction $action,
    ): RedirectResponse {
        $notes = $request->validated('notes');
        $action->execute($order, $notes !== null ? (string) $notes : null);

        return back()->with('success', __('Notes saved.'));
    }

    public function storeComment(
        AddOrderCommentRequest $request,
        Order $order,
        AddOrderCommentAction $action,
    ): RedirectResponse {
        /** @var list<string> $mentions */
        $mentions = array_values((array) $request->validated('mentions', []));

        $action->execute($order, (string) $request->validated('content'), $mentions, $this->userId($request));

        return back();
    }

    /**
     * Add lines to an existing order. Guarded by the same 1-hour edit window
     * as the API (App\Services\Orders\OrderEditGuard, called from inside the
     * Action), so this needs no extra check here.
     */
    public function addItems(AddOrderItemsRequest $request, Order $order, AddOrderItemsAction $action): RedirectResponse
    {
        /** @var list<array<string, mixed>> $items */
        $items = (array) $request->validated('items', []);
        $action->execute($order, $items);

        return back()->with('success', __('Items added.'));
    }

    public function updateItem(UpdateOrderItemRequest $request, OrderItem $orderItem, UpdateOrderItemAction $action): RedirectResponse
    {
        $action->execute(
            $orderItem,
            $request->has('quantity') ? (int) $request->validated('quantity') : null,
            $request->has('unit_type') ? (string) $request->validated('unit_type') : null,
        );

        return back()->with('success', __('Item updated.'));
    }

    public function deleteItem(OrderItem $orderItem, DeleteOrderItemAction $action): RedirectResponse
    {
        $action->execute($orderItem);

        return back()->with('success', __('Item removed.'));
    }

    /**
     * Clone this order into a new draft: same customer, notes, shipping and
     * lines, but a fresh order number, status and history — see
     * DuplicateOrderAction. Lands on the new order the same way store() does.
     */
    public function duplicate(Request $request, Order $order, DuplicateOrderAction $action): RedirectResponse
    {
        $duplicate = $action->execute($order, $this->userId($request));

        return redirect('/orders?order='.$duplicate->getKey())->with('success', __('Order duplicated.'));
    }

    public function destroy(Order $order, DeleteOrderAction $action): RedirectResponse
    {
        $action->execute($order);

        return redirect('/orders')->with('success', __('Order deleted.'));
    }

    private function userId(Request $request): string
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        return $user->getKey();
    }
}
