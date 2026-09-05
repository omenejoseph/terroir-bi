<?php

declare(strict_types=1);

namespace App\Actions\Orders;

use App\Enums\OrderStatus;
use App\Models\Order;
use Illuminate\Support\Facades\DB;

/**
 * Moves several orders to the same status in one action — the design's
 * "Bulk actions" on the Orders list (Figma 455:1577).
 *
 * This loops the single-order UpdateOrderStatusAction inside one outer
 * transaction rather than writing `status` directly the way
 * BulkUpdateInventoryItemsAction does for its own bulk edit: that shortcut is
 * safe there because a bulk inventory edit has no side effects to preserve,
 * while an order status change does — a status_histories row and a
 * notification per order, both of which other UI (the drawer's timeline)
 * depends on existing exactly as they would for a one-at-a-time change.
 */
class BulkUpdateOrderStatusAction
{
    public function __construct(private readonly UpdateOrderStatusAction $updateStatus) {}

    /**
     * @param  list<string>  $orderIds
     */
    public function execute(array $orderIds, OrderStatus $status, ?string $note, string $changedById): int
    {
        return DB::transaction(function () use ($orderIds, $status, $note, $changedById): int {
            $orders = Order::query()->whereIn('id', $orderIds)->get();

            foreach ($orders as $order) {
                $this->updateStatus->execute($order, $status, $note, $changedById);
            }

            return $orders->count();
        });
    }
}
