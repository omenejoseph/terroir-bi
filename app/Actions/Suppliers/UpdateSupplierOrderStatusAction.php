<?php

declare(strict_types=1);

namespace App\Actions\Suppliers;

use App\Enums\StockMovementType;
use App\Enums\SupplierOrderStatus;
use App\Models\InventoryItem;
use App\Models\SupplierOrder;
use App\Services\Inventory\StockLedger;
use Illuminate\Support\Facades\DB;

/**
 * Advance a PO's status. The transition into RECEIVED is the meaningful one: it
 * books each linked item into stock (PURCHASE_IN) and refreshes its cost_per_unit
 * from the line price (landed cost). Receiving is applied once.
 */
class UpdateSupplierOrderStatusAction
{
    public function __construct(private readonly StockLedger $ledger) {}

    public function execute(SupplierOrder $order, SupplierOrderStatus $status): SupplierOrder
    {
        return DB::transaction(function () use ($order, $status): SupplierOrder {
            $wasReceived = $order->status === SupplierOrderStatus::Received;

            if ($status === SupplierOrderStatus::Sent && $order->sent_at === null) {
                $order->sent_at = now();
            }

            if ($status === SupplierOrderStatus::Received && ! $wasReceived) {
                $this->receive($order);
                $order->received_at = now();
            }

            $order->status = $status;
            $order->save();

            return $order;
        });
    }

    private function receive(SupplierOrder $order): void
    {
        foreach ($order->items()->with('inventoryItem')->get() as $line) {
            $product = $line->inventoryItem;
            if (! $product instanceof InventoryItem) {
                continue;
            }

            $this->ledger->record($product, StockMovementType::PurchaseIn, (string) $line->quantity, $order->order_number);

            // Landed cost → cost-per-product.
            $product->cost_per_unit = $line->unit_price;
            $product->save();
        }
    }
}
