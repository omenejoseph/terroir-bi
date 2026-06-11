<?php

declare(strict_types=1);

namespace App\Queries;

use App\Models\InventoryItem;
use App\Models\OrderItem;
use App\Models\StockMovement;
use App\Support\Money\CurrencyRegistry;
use App\Support\Money\Money;
use App\Tenancy\Contracts\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Warehouse-exit "spend" analytics for FINISHED products over a date range:
 * headline totals (vs. the preceding equal-length window), a daily exit series,
 * and a per-product breakdown (on-hand, velocity, days-left, cost/revenue, and
 * a daily sparkline). Every outflow counts — exits are all negative stock
 * movements; revenue comes from non-consignment order lines in the window.
 */
class InventorySpendQuery
{
    private const MOVE_BOTTLES = "ABS(stock_movements.quantity) * (CASE WHEN inventory_items.unit IN ('case', 'cases') THEN inventory_items.bottles_per_case ELSE 1 END)";

    private const MOVE_COST = "ABS(stock_movements.quantity) * (CASE WHEN inventory_items.unit IN ('case', 'cases') THEN inventory_items.bottles_per_case ELSE 1 END) * inventory_items.cost_per_unit";

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
     * @return Builder<StockMovement>
     */
    private function exits(Carbon $from, Carbon $to)
    {
        return StockMovement::query()
            ->join('inventory_items', 'inventory_items.id', '=', 'stock_movements.inventory_item_id')
            ->where('inventory_items.category', 'FINISHED')
            ->where('stock_movements.quantity', '<', 0)
            ->whereBetween('stock_movements.created_at', [$from, $to]);
    }

    /**
     * @return array<string, mixed>
     */
    private function summary(Carbon $from, Carbon $to): array
    {
        $currency = $this->currency();

        $units = (int) round((float) $this->exits($from, $to)->sum(DB::raw(self::MOVE_BOTTLES)));
        $movements = $this->exits($from, $to)->count();
        $cost = (int) round((float) $this->exits($from, $to)
            ->whereNotNull('inventory_items.cost_per_unit')->sum(DB::raw(self::MOVE_COST)));
        $distinct = $this->exits($from, $to)->distinct()->count('stock_movements.inventory_item_id');

        $revenue = (int) OrderItem::query()
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->join('inventory_items', 'inventory_items.id', '=', 'order_items.inventory_item_id')
            ->where('inventory_items.category', 'FINISHED')
            ->where('orders.is_consignment', false)
            ->whereBetween('orders.created_at', [$from, $to])
            ->sum('order_items.total');

        return [
            'units_exited' => $units,
            'movements' => $movements,
            'cost_value' => Money::fromMinor($cost, $currency)->jsonSerialize(),
            'revenue' => Money::fromMinor($revenue, $currency)->jsonSerialize(),
            'distinct_skus' => $distinct,
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
     * Per-item, per-day exited bottles within the window.
     *
     * @return array<string, array<string, float>> item id => (date => bottles)
     */
    private function dailyByItem(Carbon $from, Carbon $to): array
    {
        $rows = $this->exits($from, $to)
            ->selectRaw('stock_movements.inventory_item_id as iid, stock_movements.created_at as created_at, '.self::MOVE_BOTTLES.' as bottles')
            ->get();

        /** @var array<string, array<string, float>> $byItem */
        $byItem = [];
        foreach ($rows as $row) {
            $iid = (string) $row->getAttribute('iid');
            $date = $row->created_at?->format('Y-m-d') ?? '';
            $byItem[$iid][$date] = ($byItem[$iid][$date] ?? 0) + (float) $row->getAttribute('bottles');
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
     * @return array<string, float> item id => exited bottles
     */
    private function unitsByItem(Carbon $from, Carbon $to): array
    {
        return $this->exits($from, $to)
            ->groupBy('stock_movements.inventory_item_id')
            ->selectRaw('stock_movements.inventory_item_id as iid, SUM('.self::MOVE_BOTTLES.') as units')
            ->get()
            ->mapWithKeys(fn (StockMovement $m): array => [(string) $m->getAttribute('iid') => (float) $m->getAttribute('units')])
            ->all();
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
