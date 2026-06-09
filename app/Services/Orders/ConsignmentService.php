<?php

declare(strict_types=1);

namespace App\Services\Orders;

use App\Enums\ConsignmentReportKind;
use App\Models\ConsignmentReport;
use App\Models\InventoryItem;
use App\Models\Order;
use App\Models\OrderItem;
use App\Support\Money\CurrencyRegistry;
use App\Support\Money\Money;

/**
 * Reconciliation maths for consignment (komisija) orders. Everything is tracked
 * in single bottles: a line placed in cases is multiplied by bottles_per_case,
 * and per-bottle price/cost are derived from the placement line.
 */
class ConsignmentService
{
    /**
     * Per-line tally keyed by order_item_id.
     *
     * @return array<string, array{order_item: OrderItem, placed: int, sold: int, returned: int, remaining: int, per_bottle_price: int, per_bottle_cost: int|null, revenue_minor: int}>
     */
    public function tally(Order $order): array
    {
        $order->loadMissing('items.inventoryItem', 'consignmentReports.items');

        $sold = [];
        $returned = [];
        $revenue = [];

        foreach ($order->consignmentReports as $report) {
            foreach ($report->items as $line) {
                $id = $line->order_item_id;
                if ($report->kind === ConsignmentReportKind::Sale) {
                    $sold[$id] = ($sold[$id] ?? 0) + $line->quantity;
                    $revenue[$id] = ($revenue[$id] ?? 0) + $line->total->getMinorAmount();
                } else {
                    $returned[$id] = ($returned[$id] ?? 0) + $line->quantity;
                }
            }
        }

        $result = [];
        foreach ($order->items as $item) {
            $id = $item->getKey();
            $bpc = $this->bottlesPerCase($item);
            $placed = $this->toBottles($item->quantity, $item->unit_type, $bpc);

            $result[$id] = [
                'order_item' => $item,
                'placed' => $placed,
                'sold' => $sold[$id] ?? 0,
                'returned' => $returned[$id] ?? 0,
                'remaining' => $placed - ($sold[$id] ?? 0) - ($returned[$id] ?? 0),
                'per_bottle_price' => $this->perBottle($item->unit_price->getMinorAmount(), $item->unit_type, $bpc),
                'per_bottle_cost' => $item->cost_per_unit !== null
                    ? $this->perBottle($item->cost_per_unit->getMinorAmount(), $item->unit_type, $bpc)
                    : null,
                'revenue_minor' => $revenue[$id] ?? 0,
            ];
        }

        return $result;
    }

    /**
     * @return array<string, mixed>
     */
    public function summary(Order $order): array
    {
        $tally = $this->tally($order);
        $currency = $this->currency($order);

        $placed = $sold = $returned = $remaining = $revenue = $cogs = 0;
        $lines = [];

        foreach ($tally as $id => $t) {
            $product = $t['order_item']->inventoryItem;
            $lineCogs = $t['per_bottle_cost'] !== null ? $t['per_bottle_cost'] * $t['sold'] : null;

            $placed += $t['placed'];
            $sold += $t['sold'];
            $returned += $t['returned'];
            $remaining += $t['remaining'];
            $revenue += $t['revenue_minor'];
            $cogs += $lineCogs ?? 0;

            $lines[] = [
                'order_item_id' => $id,
                'name' => $product instanceof InventoryItem ? $product->name : $t['order_item']->custom_description,
                'placed' => $t['placed'],
                'sold' => $t['sold'],
                'returned' => $t['returned'],
                'remaining' => $t['remaining'],
                'per_bottle_price' => Money::fromMinor($t['per_bottle_price'], $currency)->jsonSerialize(),
                'revenue' => Money::fromMinor($t['revenue_minor'], $currency)->jsonSerialize(),
                'cogs' => $lineCogs !== null ? Money::fromMinor($lineCogs, $currency)->jsonSerialize() : null,
            ];
        }

        $profit = $revenue - $cogs;

        return [
            'is_consignment' => $order->is_consignment,
            'closed_at' => $order->consignment_closed_at?->toIso8601String(),
            'lines' => $lines,
            'totals' => [
                'placed' => $placed,
                'sold' => $sold,
                'returned' => $returned,
                'remaining' => $remaining,
                'revenue' => Money::fromMinor($revenue, $currency)->jsonSerialize(),
                'cogs' => Money::fromMinor($cogs, $currency)->jsonSerialize(),
                'profit' => Money::fromMinor($profit, $currency)->jsonSerialize(),
                'margin_percent' => $revenue > 0 ? number_format($profit / $revenue * 100, 2, '.', '') : '0.00',
            ],
            'history' => $order->consignmentReports
                ->sortBy('date')
                ->values()
                ->map(fn (ConsignmentReport $r) => [
                    'id' => $r->getKey(),
                    'kind' => $r->kind->value,
                    'date' => $r->date->toIso8601String(),
                    'note' => $r->note,
                ])->all(),
        ];
    }

    public function currency(Order $order): string
    {
        return $order->items->first()?->unit_price->getCurrencyCode() ?? CurrencyRegistry::default()->code;
    }

    private function bottlesPerCase(OrderItem $item): int
    {
        $product = $item->inventoryItem;

        return $product instanceof InventoryItem ? max(1, (int) $product->bottles_per_case) : 1;
    }

    private function toBottles(int $quantity, string $unitType, int $bottlesPerCase): int
    {
        return $unitType === 'cases' ? $quantity * $bottlesPerCase : $quantity;
    }

    private function perBottle(int $minor, string $unitType, int $bottlesPerCase): int
    {
        return $unitType === 'cases' ? intdiv($minor, $bottlesPerCase) : $minor;
    }
}
