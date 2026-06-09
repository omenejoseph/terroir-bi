<?php

declare(strict_types=1);

namespace App\Actions\Orders;

use App\Models\Order;
use App\Services\Inventory\StockLedger;
use Illuminate\Support\Facades\DB;

/**
 * Delete an order (ADMIN), restocking each catalog line of a non-backorder
 * order. Children (items, status history, notes) cascade via FK.
 */
class DeleteOrderAction
{
    public function __construct(private readonly StockLedger $ledger) {}

    public function execute(Order $order): void
    {
        DB::transaction(function () use ($order): void {
            if (! $order->is_backorder) {
                foreach ($order->items()->get() as $item) {
                    $product = $item->inventoryItem;

                    if ($product !== null) {
                        $this->ledger->restore(
                            $product,
                            (string) $item->quantity,
                            $item->unit_type,
                            reference: $order->order_number.':deleted',
                        );
                    }
                }
            }

            $order->delete();
        });
    }
}
