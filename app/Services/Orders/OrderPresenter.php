<?php

declare(strict_types=1);

namespace App\Services\Orders;

use App\Authorization\MembershipContext;
use App\DataTransferObjects\OrderData;
use App\Models\Order;
use App\Models\OrderItem;
use App\Services\Finance\OrderPaymentSummary;
use App\Services\Uploads\PresignedUploadService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;

/**
 * Turns orders into the shape the clients render.
 *
 * Both transports use this: the API returns it as JSON, the Inertia web
 * controller passes it straight through as a page prop. Financial visibility,
 * the payment summary and the presigned line thumbnails are decided once here,
 * so the JSON API and the Vue drawer cannot disagree about what a viewer is
 * allowed to see.
 */
class OrderPresenter
{
    public function __construct(
        private readonly MembershipContext $membership,
        private readonly OrderPaymentSummary $payments,
        private readonly PresignedUploadService $uploads,
    ) {}

    /**
     * A page of orders for the list. Payments are omitted: the list shows the
     * order total, and resolving payment state per row would be a query each.
     *
     * @param  LengthAwarePaginator<int, Order>  $paginator
     * @return array{data: list<array<string, mixed>>, meta: array{current_page:int, last_page:int, per_page:int, total:int}}
     */
    public function page(LengthAwarePaginator $paginator): array
    {
        /** @var list<Order> $orders */
        $orders = $paginator->items();

        $this->eagerLoad(EloquentCollection::make($orders));

        return [
            'data' => array_map(fn (Order $order): array => $this->row($order), $orders),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ];
    }

    /**
     * One order as it appears in the list: no payment summary, for the reason
     * `page()` gives.
     *
     * @return array<string, mixed>
     */
    public function row(Order $order): array
    {
        return $this->present($order, withPayments: false);
    }

    /**
     * One order with everything the Order — View drawer (Figma 376:1592) shows:
     * lines, profitability, the status timeline and the comment thread.
     *
     * @return array<string, mixed>
     */
    public function detail(Order $order): array
    {
        return $this->present($order, withPayments: true);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(Order $order, bool $withPayments): array
    {
        $this->eagerLoad(EloquentCollection::make([$order]));

        $payment = $withPayments && $this->membership->can('finance.view')
            ? $this->payments->for($order)
            : null;

        return OrderData::fromModel(
            $order,
            $this->membership->canSeeFinancials(),
            $payment,
            $this->itemImageUrls($order),
        )->toArray();
    }

    /**
     * @param  EloquentCollection<int, Order>  $orders
     */
    private function eagerLoad(EloquentCollection $orders): void
    {
        $orders->loadMissing([
            'customer',
            'createdBy',
            'items.inventoryItem.firstImage',
            'statusHistories.changedBy',
            'orderNotes.author',
        ]);
    }

    /**
     * Presigned lead-image URL per order-item id, mirroring the inventory list
     * thumbnail. Null for custom lines or catalog items without an image.
     * `firstImage` is eager-loaded above, so this adds no extra queries.
     *
     * @return array<string, string|null>
     */
    private function itemImageUrls(Order $order): array
    {
        $urls = [];

        foreach ($order->items as $item) {
            /** @var OrderItem $item */
            $key = $item->inventoryItem?->firstImage?->object_key;
            $urls[$item->getKey()] = $key !== null ? $this->uploads->readUrl($key) : null;
        }

        return $urls;
    }
}
