<?php

declare(strict_types=1);

namespace App\Actions\Orders;

use App\Models\OrderItem;
use App\Services\Inventory\StockLedger;
use App\Services\Orders\OrderEditGuard;
use App\Services\Orders\OrderTotals;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Remove a line within the edit window, restocking it (catalog, non-backorder)
 * and re-totalling. The last line cannot be removed — delete the order instead.
 */
class DeleteOrderItemAction
{
    public function __construct(
        private readonly OrderEditGuard $guard,
        private readonly StockLedger $ledger,
        private readonly OrderTotals $totals,
    ) {}

    public function execute(OrderItem $item): void
    {
        $order = $item->order()->firstOrFail();
        $this->guard->ensureEditable($order);

        if ($order->items()->count() <= 1) {
            throw ValidationException::withMessages([
                'item' => 'An order must keep at least one line. Delete the order instead.',
            ]);
        }

        DB::transaction(function () use ($item, $order): void {
            $product = $item->inventoryItem;

            if ($product !== null && ! $order->is_backorder) {
                $this->ledger->restore($product, (string) $item->quantity, $item->unit_type, reference: $order->order_number.':remove');
            }

            $item->delete();
            $this->totals->recompute($order);
        });
    }
}
