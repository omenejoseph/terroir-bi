<?php

declare(strict_types=1);

namespace App\Services\Orders;

use App\Exceptions\InsufficientStockException;
use App\Models\InventoryItem;
use App\Models\Order;
use App\Models\OrderItem;
use App\Services\Inventory\StockLedger;
use App\Support\Quantity;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Stock set aside for a customer. An allocation order (`Order::holdsAllocation()`) keeps its goods in
 * the cellar — `current_stock` is untouched, no ledger movement exists — but they are not free to sell:
 *
 *     free to sell = on hand (current_stock) − allocated
 *
 * That makes allocation the one thing that stops another order, or a self-service order from the order
 * link, taking bottles promised to someone else. Shipping the allocation turns it into an ordinary
 * deduction (`convertOnShip`), and deleting it simply lets the reservation go — there is nothing to put back.
 *
 * Quantities are in the item's storage unit (bottles, cases, kg…), the unit `current_stock` is in.
 */
class AllocationService
{
    public function __construct(private readonly StockLedger $ledger) {}

    /** How much of the item open allocations hold, in its storage unit. */
    public function allocated(InventoryItem $item): string
    {
        $total = '0.000';

        foreach ($this->lines($item) as $line) {
            $total = Quantity::add($total, $this->ledger->toStorageQuantity($item, (string) $line->quantity, $line->unit_type));
        }

        return $total;
    }

    /** On hand minus what is allocated; negative means more has been promised than the cellar holds. */
    public function freeToSell(InventoryItem $item): string
    {
        return Quantity::sub((string) $item->current_stock, $this->allocated($item));
    }

    /**
     * Who the item is allocated to, one row per order.
     *
     * @return list<array{order_id: string, order_number: string, customer: string|null, quantity: string}>
     */
    public function breakdown(InventoryItem $item): array
    {
        $byOrder = [];

        foreach ($this->lines($item) as $line) {
            $order = $line->order;
            if ($order === null) {
                continue;
            }
            $byOrder[$order->getKey()] ??= [
                'order_id' => (string) $order->getKey(),
                'order_number' => $order->order_number,
                'customer' => $order->customer?->company_name,
                'quantity' => '0.000',
            ];
            $byOrder[$order->getKey()]['quantity'] = Quantity::add(
                $byOrder[$order->getKey()]['quantity'],
                $this->ledger->toStorageQuantity($item, (string) $line->quantity, $line->unit_type),
            );
        }

        return array_values($byOrder);
    }

    /**
     * Before an ordinary order takes stock: it may not eat into what is set aside for someone else.
     * Silent when nothing is allocated, so the ledger's own "not enough stock" message stays the one
     * people see in the ordinary case.
     *
     * @throws ValidationException
     */
    public function assertCanDeduct(InventoryItem $item, string $quantity, string $unitType): void
    {
        $allocated = $this->allocated($item);

        if (Quantity::compare($allocated, '0') <= 0) {
            return;
        }

        $needed = $this->ledger->toStorageQuantity($item, $quantity, $unitType);
        $free = Quantity::sub((string) $item->fresh()?->current_stock, $allocated);

        if (Quantity::compare($free, $needed) < 0) {
            throw ValidationException::withMessages([
                'items' => __('Not enough unallocated stock for :name — :free :unit free to sell, :allocated :unit set aside for other orders (need :needed).', [
                    'name' => $item->name,
                    'free' => Quantity::compare($free, '0') < 0 ? '0' : $free,
                    'unit' => $item->unit,
                    'allocated' => $allocated,
                    'needed' => $needed,
                ]),
            ]);
        }
    }

    /**
     * After allocation lines were written or enlarged: the cellar must be able to cover everything
     * promised, this order's lines included.
     *
     * @throws ValidationException
     */
    public function assertNotOverAllocated(InventoryItem $item): void
    {
        $item->refresh();
        $free = $this->freeToSell($item);

        if (Quantity::isNegative($free)) {
            throw ValidationException::withMessages([
                'items' => __('Not enough stock to allocate :name — :onHand :unit on hand, :short short once everything already allocated is counted.', [
                    'name' => $item->name,
                    'onHand' => (string) $item->current_stock,
                    'unit' => $item->unit,
                    'short' => Quantity::negate($free),
                ]),
            ]);
        }
    }

    /**
     * Shipping an allocation: the goods leave now, so each catalog line becomes a real deduction and the
     * order turns into an ordinary one (`deduct_stock`). Runs inside the status change's transaction —
     * if the cellar no longer holds the goods, the order does not ship.
     *
     * @throws ValidationException
     */
    public function convertOnShip(Order $order): void
    {
        if (! $order->holdsAllocation()) {
            return;
        }

        foreach ($order->items()->with('inventoryItem')->get() as $line) {
            $product = $line->inventoryItem;

            if ($product === null) {
                continue;
            }

            try {
                $this->ledger->deduct($product, (string) $line->quantity, $line->unit_type, $order->order_number);
            } catch (InsufficientStockException $e) {
                throw ValidationException::withMessages([
                    'status' => __('Cannot ship: :message', ['message' => $e->getMessage()]),
                ]);
            }
        }

        $order->deduct_stock = true;
        $order->save();
    }

    /** @return Collection<int, OrderItem> */
    private function lines(InventoryItem $item): Collection
    {
        return OrderItem::query()
            ->where('inventory_item_id', $item->getKey())
            ->whereHas('order', fn ($q) => $q->scopes(['holdingAllocation']))
            ->with('order.customer')
            ->get();
    }
}
