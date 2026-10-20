<?php

declare(strict_types=1);

namespace App\Queries;

use App\Enums\OrderStatus;
use App\Models\InventoryItem;
use App\Models\Order;
use App\Models\OrderItem;
use App\Services\Inventory\StockLedger;
use App\Services\Orders\AllocationService;
use App\Services\Orders\ConsignmentService;
use App\Support\Quantity;

/**
 * The Item — View drawer's stock waterfall (Figma 378:1592): what is physically here, how much of it is
 * spoken for, and what is left to sell. All quantities are in the item's storage unit.
 *
 *     on hand − allocated = free to sell
 *
 * Only an allocation is subtracted: it is the one commitment that leaves the goods in the cellar, and so
 * in `on_hand`. The other two lines the design draws — open orders and consignment — are goods that have
 * ALREADY left `on_hand` (an order deducts when it is placed, a consignment when it is placed with the
 * customer), so they are reported alongside for context and never taken off a second time.
 */
class ItemAvailabilityQuery
{
    public function __construct(
        private readonly AllocationService $allocations,
        private readonly ConsignmentService $consignment,
        private readonly StockLedger $ledger,
    ) {}

    /**
     * @return array{item_id: string, unit: string, on_hand: string, allocated: string, free_to_sell: string, allocations: list<array{order_id: string, order_number: string, customer: string|null, quantity: string}>, in_open_orders: string, on_consignment: string}
     */
    public function get(InventoryItem $item): array
    {
        $allocated = $this->allocations->allocated($item);

        return [
            'item_id' => (string) $item->getKey(),
            'unit' => (string) $item->unit,
            'on_hand' => (string) $item->current_stock,
            'allocated' => $allocated,
            'free_to_sell' => Quantity::sub((string) $item->current_stock, $allocated),
            'allocations' => $this->allocations->breakdown($item),
            'in_open_orders' => $this->inOpenOrders($item),
            'on_consignment' => $this->onConsignment($item),
        ];
    }

    /** Taken off the shelf by orders that have not shipped yet (consignments and allocations excluded). */
    private function inOpenOrders(InventoryItem $item): string
    {
        $total = '0.000';

        $lines = OrderItem::query()
            ->where('inventory_item_id', $item->getKey())
            ->whereHas('order', fn ($q) => $q
                ->where('status', '!=', OrderStatus::Shipped->value)
                ->where('deduct_stock', true)
                ->where('is_consignment', false))
            ->get(['quantity', 'unit_type']);

        foreach ($lines as $line) {
            $total = Quantity::add($total, $this->ledger->toStorageQuantity($item, (string) $line->quantity, $line->unit_type));
        }

        return $total;
    }

    /** Still with customers on open consignments: placed, less what they sold or returned. */
    private function onConsignment(InventoryItem $item): string
    {
        $bottles = 0;

        $orders = Order::query()
            ->where('is_consignment', true)
            ->whereNull('consignment_closed_at')
            ->whereHas('items', fn ($q) => $q->where('inventory_item_id', $item->getKey()))
            ->get();

        foreach ($orders as $order) {
            foreach ($this->consignment->tally($order) as $line) {
                if ($line['order_item']->inventory_item_id === $item->getKey()) {
                    $bottles += max(0, $line['remaining']);
                }
            }
        }

        return $this->ledger->toStorageQuantity($item, (string) $bottles, 'bottles');
    }
}
