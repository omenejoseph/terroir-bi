<?php

declare(strict_types=1);

namespace App\Queries;

use App\Enums\StockMovementType;
use App\Models\InventoryItem;
use App\Models\OrderItem;
use App\Models\StockMovement;
use App\Support\Money\CurrencyRegistry;
use App\Support\Money\Money;
use App\Tenancy\Contracts\TenantContext;
use Illuminate\Support\Carbon;

/**
 * Warehouse-exit "spend" analytics for FINISHED products over a date range:
 * headline totals (vs. the preceding equal-length window), a daily exit series,
 * and a per-product breakdown (on-hand, velocity, days-left, cost/revenue, and
 * a daily sparkline).
 *
 * Exits are GENUINE negative stock movements only — and they SELF-HEAL at read
 * time (invariant X2 of the Essentials scenario database):
 * - ADJUSTMENT rows and operator-tagged reconciliations (is_reconciliation)
 *   are count corrections, not bottles leaving; counting them breaks the
 *   produced = on-hand + exited identity (the historical "R3 shows 5,103
 *   exits" bug — INV-001/002/003).
 * - ORDER_DEDUCT rows are validated against the LIVE order: a deleted order or
 *   removed line contributes nothing (INV-006), and an order edited down
 *   scales its deducts to the live quantity (INV-005 / ORD-008) — downward
 *   only, with no data rewrite.
 * - Each movement converts to bottle-equivalents by its OWN recorded unit,
 *   falling back to the item's storage unit for legacy rows (INV-004).
 *
 * Revenue comes from non-consignment order lines (live, so always correct).
 */
class InventorySpendQuery
{
    /** Daily series + per-product sparklines are only materialised for windows up to this many days. */
    private const SPARKLINE_MAX_DAYS = 92;

    public function __construct(private readonly TenantContext $tenant) {}

    /**
     * @return array<string, mixed>
     */
    public function get(Carbon $from, Carbon $to): array
    {
        $days = max(1, (int) $from->diffInDays($to) + 1);
        $prevTo = $from->copy()->subSecond();
        $prevFrom = $from->copy()->subDays($days);
        $detailed = $days <= self::SPARKLINE_MAX_DAYS;

        return [
            'period' => ['from' => $from->toIso8601String(), 'to' => $to->toIso8601String(), 'days' => $days],
            'previous_period' => ['from' => $prevFrom->toIso8601String(), 'to' => $prevTo->toIso8601String(), 'days' => $days],
            'summary' => $this->summary($from, $to),
            'previous' => $this->summary($prevFrom, $prevTo),
            'daily' => $this->daily($from, $to, $detailed),
            'per_product' => $this->perProduct($from, $to, $prevFrom, $prevTo, $days, $detailed),
        ];
    }

    /**
     * One reconciled exit row: bottle-equivalents already healed against the
     * live order (see the class docblock).
     *
     * @return list<array{iid: string, date: string, bottles: float, cost_minor: float}>
     */
    private function reconciledExits(Carbon $from, Carbon $to): array
    {
        // Genuine exits only: negative, non-ADJUSTMENT, not operator-tagged
        // count corrections.
        $rows = StockMovement::query()
            ->join('inventory_items', 'inventory_items.id', '=', 'stock_movements.inventory_item_id')
            ->where('inventory_items.category', 'FINISHED')
            ->where('stock_movements.quantity', '<', 0)
            ->where('stock_movements.type', '!=', StockMovementType::Adjustment->value)
            ->where('stock_movements.is_reconciliation', false)
            ->whereBetween('stock_movements.created_at', [$from, $to])
            ->get([
                'stock_movements.id as mid',
                'stock_movements.inventory_item_id as iid',
                'stock_movements.created_at as moved_at',
                'stock_movements.quantity as qty',
                'stock_movements.unit as move_unit',
                'stock_movements.type as move_type',
                'stock_movements.reference as ref',
                'inventory_items.unit as item_unit',
                'inventory_items.bottles_per_case as bpc',
                'inventory_items.cost_per_unit as cost_minor',
            ]);

        $toBottles = function (float $qty, ?string $moveUnit, string $itemUnit, int $bpc): float {
            $unit = $moveUnit !== null && $moveUnit !== '' ? $moveUnit : $itemUnit;

            return abs($qty) * (in_array(strtolower($unit), ['case', 'cases'], true) ? max(1, $bpc) : 1);
        };

        // Self-healing for ORDER_DEDUCT: scale each deduct so that, per
        // (order, item), the deducts never claim more than the LIVE order line
        // quantity. Deleted orders / removed lines scale to zero. Downward
        // only — when recorded deducts already equal the live quantity (an
        // order edited up writes restore+deduct), the factor caps at 1.
        $refs = [];
        foreach ($rows as $row) {
            if ((string) $row->getAttribute('move_type') === StockMovementType::OrderDeduct->value && $row->getAttribute('ref') !== null) {
                $refs[(string) $row->getAttribute('ref')] = true;
            }
        }
        $factors = $refs === [] ? [] : $this->deductFactors(array_keys($refs));

        $out = [];
        foreach ($rows as $row) {
            $iid = (string) $row->getAttribute('iid');
            $bottles = $toBottles(
                (float) $row->getAttribute('qty'),
                $row->getAttribute('move_unit') !== null ? (string) $row->getAttribute('move_unit') : null,
                (string) $row->getAttribute('item_unit'),
                (int) $row->getAttribute('bpc'),
            );

            if ((string) $row->getAttribute('move_type') === StockMovementType::OrderDeduct->value && $row->getAttribute('ref') !== null) {
                $bottles *= $factors[(string) $row->getAttribute('ref')][$iid] ?? 0.0;
            }

            if ($bottles <= 0.0) {
                continue;
            }

            $costMinor = $row->getAttribute('cost_minor');
            $movedAt = $row->getAttribute('moved_at');

            $out[] = [
                'iid' => $iid,
                'date' => $movedAt instanceof \DateTimeInterface
                    ? $movedAt->format('Y-m-d')
                    : Carbon::parse((string) $movedAt)->format('Y-m-d'),
                'bottles' => $bottles,
                'cost_minor' => $costMinor !== null ? $bottles * (float) $costMinor : 0.0,
            ];
        }

        return $out;
    }

    /**
     * Per (order number, item): the scale factor applied to that order's
     * ORDER_DEDUCT movements so they sum to the live order quantity at most.
     *
     * @param  list<string>  $orderNumbers
     * @return array<string, array<string, float>> order number => item id => factor (0..1)
     */
    private function deductFactors(array $orderNumbers): array
    {
        // Live bottles per (order, item) from the orders as they read TODAY.
        $liveLines = OrderItem::query()
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->join('inventory_items', 'inventory_items.id', '=', 'order_items.inventory_item_id')
            ->whereIn('orders.order_number', $orderNumbers)
            ->get([
                'orders.order_number as ref',
                'order_items.inventory_item_id as iid',
                'order_items.quantity as qty',
                'order_items.unit_type as unit_type',
                'inventory_items.bottles_per_case as bpc',
            ]);

        /** @var array<string, array<string, float>> $live */
        $live = [];
        foreach ($liveLines as $line) {
            $bottles = (float) $line->getAttribute('qty')
                * (in_array(strtolower((string) $line->getAttribute('unit_type')), ['case', 'cases'], true)
                    ? max(1, (int) $line->getAttribute('bpc'))
                    : 1);
            $ref = (string) $line->getAttribute('ref');
            $iid = (string) $line->getAttribute('iid');
            $live[$ref][$iid] = ($live[$ref][$iid] ?? 0.0) + $bottles;
        }

        // Total recorded deducts per (order, item) across ALL TIME, in
        // bottle-equivalents by each movement's own unit.
        $deducts = StockMovement::query()
            ->join('inventory_items', 'inventory_items.id', '=', 'stock_movements.inventory_item_id')
            ->where('stock_movements.type', StockMovementType::OrderDeduct->value)
            ->whereIn('stock_movements.reference', $orderNumbers)
            ->where('stock_movements.quantity', '<', 0)
            ->get([
                'stock_movements.reference as ref',
                'stock_movements.inventory_item_id as iid',
                'stock_movements.quantity as qty',
                'stock_movements.unit as move_unit',
                'inventory_items.unit as item_unit',
                'inventory_items.bottles_per_case as bpc',
            ]);

        /** @var array<string, array<string, float>> $total */
        $total = [];
        foreach ($deducts as $d) {
            $unit = $d->getAttribute('move_unit') !== null && (string) $d->getAttribute('move_unit') !== ''
                ? (string) $d->getAttribute('move_unit')
                : (string) $d->getAttribute('item_unit');
            $bottles = abs((float) $d->getAttribute('qty'))
                * (in_array(strtolower($unit), ['case', 'cases'], true) ? max(1, (int) $d->getAttribute('bpc')) : 1);
            $ref = (string) $d->getAttribute('ref');
            $iid = (string) $d->getAttribute('iid');
            $total[$ref][$iid] = ($total[$ref][$iid] ?? 0.0) + $bottles;
        }

        $factors = [];
        foreach ($total as $ref => $byItem) {
            foreach ($byItem as $iid => $deducted) {
                $liveBottles = $live[$ref][$iid] ?? 0.0; // order/line gone => 0
                $factors[$ref][$iid] = $deducted > 0.0 ? min(1.0, $liveBottles / $deducted) : 0.0;
            }
        }

        return $factors;
    }

    /**
     * @return array<string, mixed>
     */
    private function summary(Carbon $from, Carbon $to): array
    {
        $currency = $this->currency();
        $exits = $this->reconciledExits($from, $to);

        $units = 0.0;
        $cost = 0.0;
        $skus = [];
        foreach ($exits as $exit) {
            $units += $exit['bottles'];
            $cost += $exit['cost_minor'];
            $skus[$exit['iid']] = true;
        }

        $revenue = (int) OrderItem::query()
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->join('inventory_items', 'inventory_items.id', '=', 'order_items.inventory_item_id')
            ->where('inventory_items.category', 'FINISHED')
            ->where('orders.is_consignment', false)
            ->whereBetween('orders.created_at', [$from, $to])
            ->sum('order_items.total');

        return [
            'units_exited' => (int) round($units),
            'movements' => count($exits),
            'cost_value' => Money::fromMinor((int) round($cost), $currency)->jsonSerialize(),
            'revenue' => Money::fromMinor($revenue, $currency)->jsonSerialize(),
            'distinct_skus' => count($skus),
        ];
    }

    /**
     * Total exited units per day. Filled for every day when the window is short;
     * otherwise only days with activity.
     *
     * @return list<array{date: string, units: int}>
     */
    private function daily(Carbon $from, Carbon $to, bool $detailed): array
    {
        $byDay = $this->dailyByItem($from, $to);

        /** @var array<string, float> $totals */
        $totals = [];
        foreach ($byDay as $perItem) {
            foreach ($perItem as $date => $bottles) {
                $totals[$date] = ($totals[$date] ?? 0) + $bottles;
            }
        }

        if (! $detailed) {
            ksort($totals);

            return array_map(
                fn (string $date, float $units): array => ['date' => $date, 'units' => (int) round($units)],
                array_keys($totals),
                array_values($totals),
            );
        }

        $series = [];
        $cursor = $from->copy()->startOfDay();
        $end = $to->copy()->startOfDay();
        while ($cursor->lessThanOrEqualTo($end)) {
            $date = $cursor->format('Y-m-d');
            $series[] = ['date' => $date, 'units' => (int) round($totals[$date] ?? 0.0)];
            $cursor->addDay();
        }

        return $series;
    }

    /**
     * Per-item, per-day exited bottles within the window (reconciled).
     *
     * @return array<string, array<string, float>> item id => (date => bottles)
     */
    private function dailyByItem(Carbon $from, Carbon $to): array
    {
        /** @var array<string, array<string, float>> $byItem */
        $byItem = [];
        foreach ($this->reconciledExits($from, $to) as $exit) {
            $byItem[$exit['iid']][$exit['date']] = ($byItem[$exit['iid']][$exit['date']] ?? 0) + $exit['bottles'];
        }

        return $byItem;
    }

    /**
     * Every active finished product with its exits, velocity, runout and sparkline.
     *
     * @return array<int, array<string, mixed>>
     */
    private function perProduct(Carbon $from, Carbon $to, Carbon $prevFrom, Carbon $prevTo, int $days, bool $detailed): array
    {
        $currency = $this->currency();
        $dailyByItem = $this->dailyByItem($from, $to);

        $unitsByItem = $this->unitsByItem($from, $to);
        $prevUnitsByItem = $this->unitsByItem($prevFrom, $prevTo);
        $revenueByItem = $this->revenueByItem($from, $to);

        // Ordered for stability; the client groups by group → subcategory for display.
        $items = InventoryItem::query()
            ->where('category', 'FINISHED')
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        $window = $this->windowDates($from, $to);

        $out = [];
        foreach ($items as $item) {
            $id = (string) $item->getKey();
            $caseUnit = in_array(strtolower((string) $item->unit), ['case', 'cases'], true);
            $bpc = max(1, (int) $item->bottles_per_case);
            $onHand = (int) round((float) $item->current_stock * ($caseUnit ? $bpc : 1));
            $units = (int) round($unitsByItem[$id] ?? 0.0);
            $velocity = $units / $days;
            $daysLeft = $units > 0 ? (int) round($onHand / max(0.0001, $velocity)) : null;
            $cost = $item->cost_per_unit !== null ? $units * $item->cost_per_unit->getMinorAmount() : null;
            $revenue = (int) round($revenueByItem[$id] ?? 0.0);

            $out[] = [
                'id' => $id,
                'name' => $item->name,
                'sku' => $item->sku,
                'vintage' => $item->vintage,
                'group' => $item->group,
                'subcategory' => $item->subcategory,
                'on_hand' => $onHand,
                'units_exited' => $units,
                'prev_units_exited' => (int) round($prevUnitsByItem[$id] ?? 0.0),
                'velocity_per_day' => number_format($velocity, 2, '.', ''),
                'days_left' => $daysLeft,
                'cost_of_exits' => $cost !== null ? Money::fromMinor($cost, $currency)->jsonSerialize() : null,
                'revenue' => $revenue > 0 ? Money::fromMinor($revenue, $currency)->jsonSerialize() : null,
                'daily' => $detailed
                    ? array_map(fn (string $d): int => (int) round($dailyByItem[$id][$d] ?? 0.0), $window)
                    : [],
            ];
        }

        return $out;
    }

    /**
     * @return array<string, float> item id => exited bottles (reconciled)
     */
    private function unitsByItem(Carbon $from, Carbon $to): array
    {
        /** @var array<string, float> $byItem */
        $byItem = [];
        foreach ($this->reconciledExits($from, $to) as $exit) {
            $byItem[$exit['iid']] = ($byItem[$exit['iid']] ?? 0.0) + $exit['bottles'];
        }

        return $byItem;
    }

    /**
     * @return array<string, float> item id => realized revenue (minor)
     */
    private function revenueByItem(Carbon $from, Carbon $to): array
    {
        return OrderItem::query()
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->join('inventory_items', 'inventory_items.id', '=', 'order_items.inventory_item_id')
            ->where('inventory_items.category', 'FINISHED')
            ->where('orders.is_consignment', false)
            ->whereBetween('orders.created_at', [$from, $to])
            ->groupBy('order_items.inventory_item_id')
            ->selectRaw('order_items.inventory_item_id as iid, SUM(order_items.total) as rev')
            ->get()
            ->mapWithKeys(fn (OrderItem $o): array => [(string) $o->getAttribute('iid') => (float) $o->getAttribute('rev')])
            ->all();
    }

    /**
     * @return list<string> 'Y-m-d' for each day in the window
     */
    private function windowDates(Carbon $from, Carbon $to): array
    {
        $dates = [];
        $cursor = $from->copy()->startOfDay();
        $end = $to->copy()->startOfDay();
        while ($cursor->lessThanOrEqualTo($end)) {
            $dates[] = $cursor->format('Y-m-d');
            $cursor->addDay();
        }

        return $dates;
    }

    private function currency(): string
    {
        $tenant = $this->tenant->current();

        return $tenant?->settings->default_currency ?? CurrencyRegistry::default()->code;
    }
}
