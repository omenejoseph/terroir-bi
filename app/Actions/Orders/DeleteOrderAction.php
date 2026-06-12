<?php

declare(strict_types=1);

namespace App\Actions\Orders;

use App\Models\Order;
use App\Services\Inventory\StockLedger;
use App\Services\Orders\ConsignmentService;
use Illuminate\Support\Facades\DB;

/**
 * Delete an order (ADMIN), restocking each catalog line of a non-backorder
 * order. Children (items, status history, notes) cascade via FK.
 *
 * Consignment orders auto-reconcile (CON-004): only the goods STILL AT THE
 * CUSTOMER come back — sold bottles are physically gone, and returned bottles
 * already went back to stock at return time. Restoring the full placement
 * would resurrect sold stock and double-count returns.
 */
class DeleteOrderAction
{
    public function __construct(
        private readonly StockLedger $ledger,
        private readonly ConsignmentService $consignment,
    ) {}

    public function execute(Order $order): void
    {
        DB::transaction(function () use ($order): void {
            if (! $order->is_backorder) {
                $tally = $order->is_consignment ? $this->consignment->tally($order) : null;

                foreach ($order->items()->get() as $item) {
                    $product = $item->inventoryItem;

                    if ($product === null) {
                        continue;
                    }

                    if ($tally !== null) {
                        // Remaining-at-customer, in single bottles.
                        $remaining = $tally[$item->getKey()]['remaining'] ?? 0;

                        if ($remaining > 0) {
                            $this->ledger->restore(
                                $product,
                                (string) $remaining,
                                'bottles',
                                reference: $order->order_number.':deleted',
                                note: "Restored remaining consignment ({$remaining} btl)",
                            );
                        }

                        continue;
                    }

                    $this->ledger->restore(
                        $product,
                        (string) $item->quantity,
                        $item->unit_type,
                        reference: $order->order_number.':deleted',
                    );
                }
            }

            $order->delete();
        });
    }
}
