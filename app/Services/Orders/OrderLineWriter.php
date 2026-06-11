<?php

declare(strict_types=1);

namespace App\Services\Orders;

use App\Enums\SalesUnit;
use App\Models\Customer;
use App\Models\InventoryItem;
use App\Models\Order;
use App\Models\OrderItem;
use App\Services\Inventory\StockLedger;
use App\Services\Pricing\PricingService;
use App\Support\Money\Money;
use Illuminate\Validation\ValidationException;

/**
 * Writes a single order line: resolves the unit price (server truth unless an
 * explicit override is given), snapshots COGS, persists the line, and — for
 * catalog lines when the order deducts stock — deducts it (overdraw-guarded).
 * Shared by order creation and add-items so the rules live in one place.
 */
class OrderLineWriter
{
    public function __construct(
        private readonly PricingService $pricing,
        private readonly CogsSnapshot $cogs,
        private readonly StockLedger $ledger,
    ) {}

    /**
     * @param  array<string, mixed>  $line  a validated order-line payload
     */
    public function write(Order $order, Customer $customer, string $currency, array $line): OrderItem
    {
        $quantity = (int) $line['quantity'];
        $requestedUnit = isset($line['unit_type']) ? (string) $line['unit_type'] : null;
        $itemId = $line['inventory_item_id'] ?? null;
        $item = $itemId !== null ? InventoryItem::query()->find((string) $itemId) : null;

        // A catalog item can only be ordered in its sales unit (strict). If the
        // line omits the unit, it defaults to the item's sales unit.
        if ($item !== null) {
            $salesUnit = (string) $item->sales_unit;
            if ($requestedUnit !== null && $requestedUnit !== $salesUnit) {
                throw ValidationException::withMessages([
                    'items' => "{$item->name} is sold in {$salesUnit}; it can't be ordered in {$requestedUnit}.",
                ]);
            }
            $unitType = $salesUnit;
        } else {
            $unitType = $requestedUnit ?? SalesUnit::Bottles->value;
        }

        $override = isset($line['unit_price']) ? (int) $line['unit_price'] : null;

        if ($item !== null) {
            // Price and cost are stored per bottle; a case line is scaled up by
            // bottles_per_case.
            $unitPriceMinor = $override ?? $this->resolvedUnitPrice($customer, $item, $unitType);
            $cost = $this->cogs->forLine($item, $unitType);
            $costMinor = $cost?->getMinorAmount();
        } else {
            $unitPriceMinor = $override ?? 0;
            $costMinor = null;
        }

        $orderItem = $order->items()->create([
            'inventory_item_id' => $item?->getKey(),
            'quantity' => $quantity,
            'unit_type' => $unitType,
            'unit_price' => Money::fromMinor($unitPriceMinor, $currency),
            'total' => Money::fromMinor($unitPriceMinor * $quantity, $currency),
            'cost_per_unit' => $costMinor !== null ? Money::fromMinor($costMinor, $currency) : null,
            'custom_description' => $line['custom_description'] ?? null,
        ]);

        if ($item !== null && $order->deduct_stock) {
            $this->ledger->deduct($item, (string) $quantity, $unitType, $order->order_number);
        }

        return $orderItem;
    }

    /** Per-bottle resolved price, scaled to a case line by bottles_per_case. */
    private function resolvedUnitPrice(Customer $customer, InventoryItem $item, string $unitType): int
    {
        $base = $this->pricing->resolve($customer, $item)->getMinorAmount();

        return $unitType === SalesUnit::Cases->value ? $base * max(1, (int) $item->bottles_per_case) : $base;
    }
}
