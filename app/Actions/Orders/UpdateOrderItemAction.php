<?php

declare(strict_types=1);

namespace App\Actions\Orders;

use App\Models\InventoryItem;
use App\Models\OrderItem;
use App\Services\Inventory\StockLedger;
use App\Services\Orders\CogsSnapshot;
use App\Services\Orders\OrderEditGuard;
use App\Services\Orders\OrderTotals;
use App\Support\Money\Money;
use Illuminate\Support\Facades\DB;

/**
 * Edit a line's quantity and/or unit type within the edit window. Reverses the
 * old stock effect and applies the new one (overdraw-guarded); rescales the unit
 * price and re-snapshots COGS when the unit type changes; re-totals the order.
 */
class UpdateOrderItemAction
{
    public function __construct(
        private readonly OrderEditGuard $guard,
        private readonly StockLedger $ledger,
        private readonly CogsSnapshot $cogs,
        private readonly OrderTotals $totals,
    ) {}

    public function execute(OrderItem $item, ?int $quantity, ?string $unitType): OrderItem
    {
        $order = $item->order()->firstOrFail();
        $this->guard->ensureEditable($order);

        return DB::transaction(function () use ($item, $order, $quantity, $unitType): OrderItem {
            $oldQty = $item->quantity;
            $oldUnit = $item->unit_type;
            $newQty = $quantity ?? $oldQty;
            $newUnit = $unitType ?? $oldUnit;

            $product = $item->inventoryItem;
            $currency = $this->totals->currency($order);

            // Re-apply stock: put the old line back, then deduct the new line.
            if ($product instanceof InventoryItem && ! $order->is_backorder) {
                $this->ledger->restore($product, (string) $oldQty, $oldUnit, reference: $order->order_number.':edit');
                $this->ledger->deduct($product, (string) $newQty, $newUnit, $order->order_number);
            }

            // Rescale price by bottles_per_case when the unit type changes.
            $unitPriceMinor = $item->unit_price->getMinorAmount();
            if ($newUnit !== $oldUnit) {
                $bpc = $product instanceof InventoryItem ? max(1, (int) $product->bottles_per_case) : 1;
                $perBottle = $oldUnit === 'cases' ? intdiv($unitPriceMinor, $bpc) : $unitPriceMinor;
                $unitPriceMinor = $newUnit === 'cases' ? $perBottle * $bpc : $perBottle;
            }

            $cost = $product instanceof InventoryItem && $newUnit !== $oldUnit
                ? $this->cogs->forLine($product, $newUnit)
                : $item->cost_per_unit;

            $item->quantity = $newQty;
            $item->unit_type = $newUnit;
            $item->unit_price = Money::fromMinor($unitPriceMinor, $currency);
            $item->total = Money::fromMinor($unitPriceMinor * $newQty, $currency);
            $item->cost_per_unit = $cost !== null
                ? Money::fromMinor($cost->getMinorAmount(), $currency)
                : null;
            $item->save();

            $this->totals->recompute($order);

            return $item;
        });
    }
}
