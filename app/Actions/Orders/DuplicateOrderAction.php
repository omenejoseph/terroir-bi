<?php

declare(strict_types=1);

namespace App\Actions\Orders;

use App\Models\Order;
use App\Models\OrderItem;

/**
 * Clone an order into a new draft: same customer, notes, shipping and lines,
 * but everything lifecycle-specific starts fresh — reuses CreateOrderAction
 * unmodified, so a duplicate gets its own order number, a RECEIVED status, a
 * new "Order created" history entry, and no copied comments, status history,
 * consignment reports or payments.
 *
 * Catalog lines are copied without their price, so OrderLineWriter re-resolves
 * today's pricing/COGS rather than carrying over a stale one; a custom line has
 * no catalog price to resolve, so its price and description carry over as-is
 * (StoreOrderRequest requires both on a custom line, same as creating one).
 * is_backorder/deduct_stock are not copied — a duplicate is a fresh decision,
 * not a continuation of the original's backorder state.
 */
class DuplicateOrderAction
{
    public function __construct(private readonly CreateOrderAction $create) {}

    public function execute(Order $order, string $actingUserId): Order
    {
        $order->loadMissing(['customer', 'items']);

        return $this->create->execute($order->customer, $actingUserId, [
            'notes' => $order->notes,
            'shipping_cost' => $order->shipping_cost?->getMinorAmount(),
            'shipping_paid_by_us' => $order->shipping_paid_by_us,
            'is_consignment' => $order->is_consignment,
            'items' => $order->items->map(fn (OrderItem $item): array => [
                'inventory_item_id' => $item->inventory_item_id,
                'quantity' => $item->quantity,
                'unit_type' => $item->unit_type,
                'custom_description' => $item->custom_description,
                ...($item->inventory_item_id === null
                    ? ['unit_price' => $item->unit_price->getMinorAmount()]
                    : []),
            ])->all(),
        ]);
    }
}
