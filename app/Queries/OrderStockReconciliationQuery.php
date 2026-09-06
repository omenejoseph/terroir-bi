<?php

declare(strict_types=1);

namespace App\Queries;

use App\Enums\StockMovementType;
use App\Models\InventoryItem;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\StockMovement;
use DateTimeInterface;
use Illuminate\Support\Carbon;

/**
 * Inventory Spend's "Check order → stock link" (Figma 386:1673): every
 * (order, item) pair whose live order state disagrees with what the stock
 * ledger actually recorded, in either direction —
 *
 *  - **never deducted**: an order line that should have moved stock
 *    (`orders.deduct_stock = true`) but has no matching `ORDER_DEDUCT`
 *    total — the exact failure mode the page's own "sitting untouched"
 *    callout warns about ("an exit that never reached the ledger looks
 *    identical to no trade at all");
 *  - **orphaned**: a recorded deduct whose order or line no longer backs
 *    it (edited down, line removed, or the whole order deleted) —
 *    `App\Queries\InventorySpendQuery::deductFactors()` already scales
 *    these out of the headline totals silently; this surfaces the same
 *    disagreement as a row instead of just correcting past it.
 *
 * Deliberately not scoped to `OrderStatus::Shipped` the way the plan's own
 * name suggests: stock is deducted at order/line write time, not on a status
 * transition (`App\Services\Orders\OrderLineWriter`), so a still-`Received`
 * order that already deducted is exactly as relevant here as a `Shipped`
 * one, and status has no bearing on whether a mismatch exists.
 */
class OrderStockReconciliationQuery
{
    /** Bottle-equivalent rounding differences below this are not a mismatch. */
    private const EPSILON = 0.001;

    /**
     * @return list<array<string, mixed>>
     */
    public function get(Carbon $from, Carbon $to): array
    {
        $orderNumbers = $this->relevantOrderNumbers($from, $to);

        if ($orderNumbers === []) {
            return [];
        }

        $recorded = $this->recordedBottles($orderNumbers);
        $live = $this->liveBottles($orderNumbers);
        $orders = $this->orderContext($orderNumbers);

        $refs = array_unique([...array_keys($recorded), ...array_keys($live)]);
        $itemIds = [];
        foreach ($refs as $ref) {
            $itemIds = [...$itemIds, ...array_keys($recorded[$ref] ?? []), ...array_keys($live[$ref] ?? [])];
        }
        $items = $this->itemsById(array_values(array_unique($itemIds)));

        $rows = [];
        foreach ($refs as $ref) {
            $refItemIds = array_unique([...array_keys($recorded[$ref] ?? []), ...array_keys($live[$ref] ?? [])]);

            foreach ($refItemIds as $itemId) {
                $recordedRow = $recorded[$ref][$itemId] ?? null;
                $recordedBottles = $recordedRow['bottles'] ?? 0.0;
                $currentBottles = $live[$ref][$itemId] ?? 0.0;
                $delta = $currentBottles - $recordedBottles;

                if (abs($delta) < self::EPSILON) {
                    continue;
                }

                // $item is never actually null here — order_items.inventory_item_id
                // only ever points at a real item, since DeleteInventoryItemAction
                // deactivates rather than deletes one still referenced by an order
                // (see its own docblock). $order genuinely can be: that's this
                // whole report's "order was deleted entirely" case.
                $item = $items[$itemId] ?? null;
                $order = $orders[$ref] ?? null;

                $rows[] = [
                    'order_number' => $ref,
                    // Null when the order itself no longer exists — a fully
                    // deleted order, not just a removed line.
                    'order_id' => $order?->getKey(),
                    'order_status' => $order?->status->value,
                    'customer_name' => $order?->customer?->company_name,
                    'item_id' => $itemId,
                    'item_name' => $item->name ?? '—',
                    'sku' => $item?->sku,
                    'recorded_bottles' => round($recordedBottles, 3),
                    'current_bottles' => round($currentBottles, 3),
                    'delta' => round($delta, 3),
                    'last_movement_at' => $recordedRow['last_at'] ?? null,
                ];
            }
        }

        usort($rows, fn (array $a, array $b): int => abs($b['delta']) <=> abs($a['delta']));

        return $rows;
    }

    /**
     * Every order number worth checking in this window: one with a recorded
     * deduct in it (an edit/delete could have happened since), or one with an
     * order created in it that was supposed to deduct (deduct_stock) — the
     * two source sets the class docblock's two failure modes each need.
     *
     * @return list<string>
     */
    private function relevantOrderNumbers(Carbon $from, Carbon $to): array
    {
        $fromMovements = StockMovement::query()
            ->where('type', StockMovementType::OrderDeduct->value)
            ->whereBetween('created_at', [$from, $to])
            ->whereNotNull('reference')
            ->distinct()
            ->pluck('reference');

        $fromOrders = Order::query()
            ->where('deduct_stock', true)
            ->whereBetween('created_at', [$from, $to])
            ->pluck('order_number');

        /** @var list<string> $numbers */
        $numbers = array_values(array_unique([...$fromMovements, ...$fromOrders]));

        return $numbers;
    }

    /**
     * Total ORDER_DEDUCT bottles ever recorded per (order number, item) —
     * all-time, not window-limited, exactly like InventorySpendQuery's own
     * deductFactors(): a mismatch is about the pair's whole history against
     * its current state, not an artifact of where the window happens to cut.
     *
     * @param  list<string>  $orderNumbers
     * @return array<string, array<string, array{bottles: float, last_at: string|null}>>
     */
    private function recordedBottles(array $orderNumbers): array
    {
        $rows = StockMovement::query()
            ->join('inventory_items', 'inventory_items.id', '=', 'stock_movements.inventory_item_id')
            ->where('stock_movements.type', StockMovementType::OrderDeduct->value)
            ->where('stock_movements.quantity', '<', 0)
            ->where('inventory_items.category', 'FINISHED')
            ->whereIn('stock_movements.reference', $orderNumbers)
            ->get([
                'stock_movements.reference as ref',
                'stock_movements.inventory_item_id as iid',
                'stock_movements.quantity as qty',
                'stock_movements.unit as move_unit',
                'stock_movements.created_at as moved_at',
                'inventory_items.unit as item_unit',
                'inventory_items.bottles_per_case as bpc',
            ]);

        /** @var array<string, array<string, array{bottles: float, last_at: string|null}>> $out */
        $out = [];
        foreach ($rows as $row) {
            $ref = (string) $row->getAttribute('ref');
            $iid = (string) $row->getAttribute('iid');
            $bottles = $this->toBottles(
                (float) $row->getAttribute('qty'),
                $row->getAttribute('move_unit') !== null ? (string) $row->getAttribute('move_unit') : null,
                (string) $row->getAttribute('item_unit'),
                (int) $row->getAttribute('bpc'),
            );

            $existing = $out[$ref][$iid] ?? ['bottles' => 0.0, 'last_at' => null];
            $movedAt = $row->getAttribute('moved_at');
            $movedAtIso = $movedAt instanceof DateTimeInterface ? $movedAt->format(DATE_ATOM) : (string) $movedAt;

            $out[$ref][$iid] = [
                'bottles' => $existing['bottles'] + $bottles,
                'last_at' => $existing['last_at'] === null || $movedAtIso > $existing['last_at'] ? $movedAtIso : $existing['last_at'],
            ];
        }

        return $out;
    }

    /**
     * Live bottles per (order number, item) from the orders as they read
     * TODAY — a deleted order or removed line simply has no entry, read as 0
     * by the caller.
     *
     * @param  list<string>  $orderNumbers
     * @return array<string, array<string, float>>
     */
    private function liveBottles(array $orderNumbers): array
    {
        $rows = OrderItem::query()
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->join('inventory_items', 'inventory_items.id', '=', 'order_items.inventory_item_id')
            ->where('inventory_items.category', 'FINISHED')
            ->whereIn('orders.order_number', $orderNumbers)
            ->get([
                'orders.order_number as ref',
                'order_items.inventory_item_id as iid',
                'order_items.quantity as qty',
                'order_items.unit_type as unit_type',
                'inventory_items.bottles_per_case as bpc',
            ]);

        /** @var array<string, array<string, float>> $out */
        $out = [];
        foreach ($rows as $row) {
            $ref = (string) $row->getAttribute('ref');
            $iid = (string) $row->getAttribute('iid');
            $bottles = (float) $row->getAttribute('qty')
                * (in_array(strtolower((string) $row->getAttribute('unit_type')), ['case', 'cases'], true)
                    ? max(1, (int) $row->getAttribute('bpc'))
                    : 1);

            $out[$ref][$iid] = ($out[$ref][$iid] ?? 0.0) + $bottles;
        }

        return $out;
    }

    /**
     * @param  list<string>  $orderNumbers
     * @return array<string, Order>
     */
    private function orderContext(array $orderNumbers): array
    {
        return Order::query()
            ->whereIn('order_number', $orderNumbers)
            ->with('customer')
            ->get()
            ->keyBy('order_number')
            ->all();
    }

    /**
     * @param  list<string>  $ids
     * @return array<string, InventoryItem>
     */
    private function itemsById(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return InventoryItem::query()->whereIn('id', $ids)->get()->keyBy('id')->all();
    }

    private function toBottles(float $qty, ?string $moveUnit, string $itemUnit, int $bpc): float
    {
        $unit = $moveUnit !== null && $moveUnit !== '' ? $moveUnit : $itemUnit;

        return abs($qty) * (in_array(strtolower($unit), ['case', 'cases'], true) ? max(1, $bpc) : 1);
    }
}
