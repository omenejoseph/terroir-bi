<?php

declare(strict_types=1);

namespace App\Queries;

use App\Enums\ConsignmentReportKind;
use App\Models\ConsignmentReportItem;
use App\Models\Customer;
use App\Models\InventoryItem;
use App\Models\Order;
use App\Support\Money\CurrencyRegistry;
use App\Support\Money\Money;
use App\Tenancy\Contracts\TenantContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Order profitability for a period — a parity port of the prototype's
 * computeProfitability. Revenue/COGS come from the per-line snapshot on the
 * current order lines (non-consignment, excluding stats-excluded customers),
 * so they're immune to the ORDER_DEDUCT double-count. Summary totals are always
 * computed over the whole period; the customer/product rankings are paginated.
 *
 * Money is integer minor units (serialised as Money objects); volumes are
 * bottles. Margins are null when revenue is zero (never divide by zero).
 */
class OrderAnalyticsQuery
{
    private const LOW_MARGIN_PCT = 20.0;

    private const MARGIN_BUCKETS = [
        ['label' => '<0%', 'min' => null, 'max' => 0],
        ['label' => '0–10%', 'min' => 0, 'max' => 10],
        ['label' => '10–20%', 'min' => 10, 'max' => 20],
        ['label' => '20–30%', 'min' => 20, 'max' => 30],
        ['label' => '30–40%', 'min' => 30, 'max' => 40],
        ['label' => '40–50%', 'min' => 40, 'max' => 50],
        ['label' => '50%+', 'min' => 50, 'max' => null],
    ];

    /** @var list<string>|null */
    private ?array $excluded = null;

    /** @var Collection<string, InventoryItem>|null */
    private ?Collection $items = null;

    public function __construct(private readonly TenantContext $tenant) {}

    /**
     * @return array<string, mixed>
     */
    public function get(Carbon $from, Carbon $to, int $customersPage = 1, int $productsPage = 1, int $perPage = 10): array
    {
        $currency = $this->currency();
        $orders = $this->loadOrders($from, $to);

        $revenue = 0;
        $cogs = 0;
        $bottles = 0;
        $totalLines = 0;
        $unknownCostLines = 0;
        $revenueWithoutCost = 0;
        $perOrderMarginPct = [];
        $customers = [];   // id => agg
        $products = [];    // id => agg
        $channels = [];    // key => agg
        $price = [];       // item id => realization agg
        $buckets = [];     // bucketKey => agg
        $linesWithoutCost = [];
        $lowMargin = [];

        $granularity = $from->diffInDays($to) > 45 ? 'week' : 'day';

        foreach ($orders as $order) {
            $created = $order->created_at instanceof Carbon ? $order->created_at : $to;
            $oRevenue = 0;
            $oCogs = 0;
            $cid = (string) $order->customer_id;
            $customer = $order->customer;
            $channelKey = $this->channel($customer);

            foreach ($order->items as $line) {
                $totalLines++;
                $lineRevenue = $line->total->getMinorAmount();
                $oRevenue += $lineRevenue;

                $item = $line->inventory_item_id !== null ? $this->items?->get($line->inventory_item_id) : null;
                $bottleEq = $this->bottles((int) $line->quantity, (string) $line->unit_type, $item);
                $bottles += $bottleEq;

                $unitCost = $line->cost_per_unit?->getMinorAmount();
                if ($unitCost === null) {
                    $unknownCostLines++;
                    $revenueWithoutCost += $lineRevenue;
                    if (count($linesWithoutCost) < 200) {
                        $linesWithoutCost[] = [
                            'order_id' => $order->getKey(),
                            'order_number' => $order->order_number,
                            'customer_name' => $customer?->company_name,
                            'date' => $created->toIso8601String(),
                            'product_name' => $item !== null ? $item->name : ($line->custom_description ?? null),
                            'quantity' => (int) $line->quantity,
                            'unit_type' => (string) $line->unit_type,
                            'line_revenue' => $this->money($lineRevenue, $currency),
                        ];
                    }
                } else {
                    $oCogs += $unitCost * (int) $line->quantity;
                }

                // Product rollup.
                if ($item !== null) {
                    $p = &$products[$line->inventory_item_id];
                    $p ??= ['name' => $item->name, 'vintage' => $item->vintage, 'qty' => 0, 'rev' => 0, 'cogs' => 0];
                    $p['qty'] += $bottleEq;
                    $p['rev'] += $lineRevenue;
                    $p['cogs'] += $unitCost !== null ? $unitCost * (int) $line->quantity : 0;
                    unset($p);

                    // Price realization (needs a list price + real units + paid line).
                    $list = $item->default_price?->getMinorAmount();
                    if ($list !== null && $list > 0 && $bottleEq > 0 && $lineRevenue > 0) {
                        $r = &$price[$line->inventory_item_id];
                        $r ??= ['name' => $item->name, 'vintage' => $item->vintage, 'qty' => 0, 'list' => $list, 'list_rev' => 0, 'real_rev' => 0];
                        $r['qty'] += $bottleEq;
                        $r['list'] = $list;
                        $r['list_rev'] += $list * $bottleEq;
                        $r['real_rev'] += $lineRevenue;
                        unset($r);
                    }
                }
            }

            $revenue += $oRevenue;
            $cogs += $oCogs;
            $oProfit = $oRevenue - $oCogs;
            $oMargin = $oRevenue > 0 ? $oProfit / $oRevenue * 100 : 0.0;
            $perOrderMarginPct[] = $oMargin;

            // Customer rollup.
            $c = &$customers[$cid];
            $c ??= ['name' => $customer?->company_name, 'rev' => 0, 'cogs' => 0, 'orders' => 0];
            $c['rev'] += $oRevenue;
            $c['cogs'] += $oCogs;
            $c['orders']++;
            unset($c);

            // Channel rollup.
            $ch = &$channels[$channelKey];
            $ch ??= ['rev' => 0, 'cogs' => 0, 'orders' => 0, 'bottles' => 0];
            $ch['rev'] += $oRevenue;
            $ch['cogs'] += $oCogs;
            $ch['orders']++;
            unset($ch);

            // Time bucket.
            [$bk, $bdate, $blabel] = $this->bucket($created, $granularity);
            $b = &$buckets[$bk];
            $b ??= ['date' => $bdate, 'label' => $blabel, 'rev' => 0, 'cogs' => 0];
            $b['rev'] += $oRevenue;
            $b['cogs'] += $oCogs;
            unset($b);

            if ($oRevenue > 0 && $oMargin < self::LOW_MARGIN_PCT) {
                $lowMargin[] = [
                    'order_id' => $order->getKey(),
                    'order_number' => $order->order_number,
                    'customer_name' => $customer?->company_name,
                    'date' => $created->toIso8601String(),
                    'revenue' => $this->money($oRevenue, $currency),
                    'gross_profit' => $this->money($oProfit, $currency),
                    'margin_percent' => number_format($oMargin, 2, '.', ''),
                ];
            }
        }

        // Channel bottles (second small pass kept inline above is messy; recompute here).
        foreach ($orders as $order) {
            $key = $this->channel($order->customer);
            foreach ($order->items as $line) {
                $item = $line->inventory_item_id !== null ? $this->items?->get($line->inventory_item_id) : null;
                $channels[$key]['bottles'] = ($channels[$key]['bottles'] ?? 0) + $this->bottles((int) $line->quantity, (string) $line->unit_type, $item);
            }
        }

        $grossProfit = $revenue - $cogs;
        $prev = $this->windowTotals(...$this->previousWindow($from, $to));

        usort($lowMargin, fn (array $a, array $b) => (float) $a['margin_percent'] <=> (float) $b['margin_percent']);

        return [
            'period' => ['from' => $from->toIso8601String(), 'to' => $to->toIso8601String()],
            'revenue' => $this->money($revenue, $currency),
            'cogs' => $this->money($cogs, $currency),
            'gross_profit' => $this->money($grossProfit, $currency),
            'margin_percent' => $revenue > 0 ? number_format($grossProfit / $revenue * 100, 2, '.', '') : '0.00',
            'order_count' => $orders->count(),
            'avg_order_value' => $this->money($orders->count() > 0 ? intdiv($revenue, $orders->count()) : 0, $currency),
            'items_with_unknown_cost' => $unknownCostLines,
            'consignment_revenue' => $this->money($this->consignmentRevenue($from, $to), $currency),

            // Secondary operational stats.
            'bottles_sold' => $bottles,
            'total_lines' => $totalLines,
            'revenue_without_cost' => $this->money($revenueWithoutCost, $currency),
            'avg_gp_per_order' => $this->money(
                $perOrderMarginPct !== [] ? (int) round(($revenue - $cogs) / max(1, $orders->count())) : 0,
                $currency,
            ),
            'avg_margin_pct_per_order' => $perOrderMarginPct !== []
                ? number_format(array_sum($perOrderMarginPct) / count($perOrderMarginPct), 2, '.', '')
                : null,

            // Period-over-period (same-length preceding window).
            'previous' => [
                'revenue' => $this->money($prev['revenue'], $currency),
                'cogs' => $this->money($prev['cogs'], $currency),
                'gross_profit' => $this->money($prev['revenue'] - $prev['cogs'], $currency),
                'margin_percent' => $prev['revenue'] > 0
                    ? number_format(($prev['revenue'] - $prev['cogs']) / $prev['revenue'] * 100, 2, '.', '')
                    : '0.00',
                'order_count' => $prev['orders'],
            ],

            'series' => $this->series($buckets, $currency),
            'granularity' => $granularity,
            'top_customers' => $this->rankPaginate($customers, $customersPage, $perPage, $currency, 'customer'),
            'top_products' => $this->rankPaginate($products, $productsPage, $perPage, $currency, 'product'),
            'channels' => $this->channelRows($channels, $revenue, $currency),
            'price_realization' => $this->priceRealization($price, $currency),
            'histogram' => $this->histogram($orders, $currency),
            'low_margin_orders' => array_slice($lowMargin, 0, 25),
            'lines_without_cost' => $linesWithoutCost,
        ];
    }

    /**
     * Orders for a window with the data needed to aggregate. Inventory items are
     * loaded once into a shared map (keyed by id) for unit/price lookups.
     *
     * @return Collection<int, Order>
     */
    private function loadOrders(Carbon $from, Carbon $to): Collection
    {
        if ($this->items === null) {
            $this->items = InventoryItem::query()
                ->get(['id', 'name', 'vintage', 'group', 'subcategory', 'unit', 'bottles_per_case', 'default_price', 'sku', 'unit_size'])
                ->keyBy('id');
        }

        return Order::query()
            ->where('is_consignment', false)
            ->whereNotIn('customer_id', $this->excludedCustomers())
            ->whereBetween('created_at', [$from, $to])
            ->with(['customer:id,company_name,customer_type,is_agency', 'items:id,order_id,inventory_item_id,quantity,unit_type,total,cost_per_unit,custom_description'])
            ->get(['id', 'order_number', 'created_at', 'customer_id']);
    }

    /**
     * Lightweight revenue/COGS/order totals for a window (used for the previous
     * period). Same basis as the main figures.
     *
     * @return array{revenue: int, cogs: int, orders: int}
     */
    private function windowTotals(Carbon $from, Carbon $to): array
    {
        $orders = $this->loadOrders($from, $to);
        $revenue = 0;
        $cogs = 0;
        foreach ($orders as $order) {
            foreach ($order->items as $line) {
                $revenue += $line->total->getMinorAmount();
                $cost = $line->cost_per_unit?->getMinorAmount();
                if ($cost !== null) {
                    $cogs += $cost * (int) $line->quantity;
                }
            }
        }

        return ['revenue' => $revenue, 'cogs' => $cogs, 'orders' => $orders->count()];
    }

    /** @return array{0: Carbon, 1: Carbon} */
    private function previousWindow(Carbon $from, Carbon $to): array
    {
        $seconds = (int) $from->diffInSeconds($to);

        return [$from->copy()->subSeconds($seconds + 1), $from->copy()->subSecond()];
    }

    /**
     * Rank an aggregate map by gross profit and return one paginated page, with
     * meta. Totals/summary are computed elsewhere over the whole set.
     *
     * @param  array<string, array<string, mixed>>  $agg
     * @return array{data: list<array<string, mixed>>, meta: array<string, int>}
     */
    private function rankPaginate(array $agg, int $page, int $perPage, string $currency, string $kind): array
    {
        $rows = [];
        foreach ($agg as $id => $a) {
            $profit = $a['rev'] - $a['cogs'];
            $row = [
                'revenue' => $this->money($a['rev'], $currency),
                'cogs' => $this->money($a['cogs'], $currency),
                'gross_profit' => $this->money($profit, $currency),
                'margin_percent' => $a['rev'] > 0 ? number_format($profit / $a['rev'] * 100, 2, '.', '') : null,
            ];
            if ($kind === 'customer') {
                $row = ['customer_id' => (string) $id, 'company_name' => $a['name'], 'orders_count' => $a['orders']] + $row;
            } else {
                $row = ['inventory_item_id' => (string) $id, 'name' => $a['name'], 'vintage' => $a['vintage'], 'quantity' => $a['qty']] + $row;
            }
            $rows[] = $row;
        }

        usort($rows, fn (array $a, array $b) => $b['gross_profit']['minor'] <=> $a['gross_profit']['minor']);

        $total = count($rows);
        $page = max(1, $page);
        $offset = ($page - 1) * $perPage;

        return [
            'data' => array_slice($rows, $offset, $perPage),
            'meta' => [
                'current_page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'last_page' => max(1, (int) ceil($total / $perPage)),
            ],
        ];
    }

    /**
     * @param  array<string, array<string, mixed>>  $buckets
     * @return list<array<string, mixed>>
     */
    private function series(array $buckets, string $currency): array
    {
        $rows = array_values($buckets);
        usort($rows, fn (array $a, array $b) => strcmp((string) $a['date'], (string) $b['date']));

        return array_map(function (array $b) use ($currency): array {
            $profit = $b['rev'] - $b['cogs'];

            return [
                'date' => $b['date'],
                'label' => $b['label'],
                'revenue' => $this->money($b['rev'], $currency),
                'cogs' => $this->money($b['cogs'], $currency),
                'gross_profit' => $this->money($profit, $currency),
                'margin_percent' => $b['rev'] > 0 ? number_format($profit / $b['rev'] * 100, 2, '.', '') : null,
            ];
        }, $rows);
    }

    /**
     * @param  array<string, array<string, mixed>>  $channels
     * @return list<array<string, mixed>>
     */
    private function channelRows(array $channels, int $totalRevenue, string $currency): array
    {
        $rows = [];
        foreach ($channels as $key => $a) {
            $profit = $a['rev'] - $a['cogs'];
            $rows[] = [
                'key' => $key,
                'revenue' => $this->money($a['rev'], $currency),
                'cogs' => $this->money($a['cogs'], $currency),
                'gross_profit' => $this->money($profit, $currency),
                'margin_percent' => $a['rev'] > 0 ? number_format($profit / $a['rev'] * 100, 2, '.', '') : null,
                'orders_count' => $a['orders'],
                'bottles' => $a['bottles'] ?? 0,
                'revenue_share_pct' => $totalRevenue > 0 ? number_format($a['rev'] / $totalRevenue * 100, 1, '.', '') : '0.0',
            ];
        }
        usort($rows, fn (array $a, array $b) => $b['gross_profit']['minor'] <=> $a['gross_profit']['minor']);

        return $rows;
    }

    /**
     * @param  array<string, array<string, mixed>>  $price
     * @return array{rows: list<array<string, mixed>>, totals: array<string, mixed>}
     */
    private function priceRealization(array $price, string $currency): array
    {
        $rows = [];
        $listTotal = 0;
        $realTotal = 0;
        foreach ($price as $id => $a) {
            $leak = $a['list_rev'] - $a['real_rev'];
            $listTotal += $a['list_rev'];
            $realTotal += $a['real_rev'];
            $rows[] = [
                'inventory_item_id' => (string) $id,
                'name' => $a['name'],
                'vintage' => $a['vintage'],
                'bottles' => $a['qty'],
                'list_price' => $this->money($a['list'], $currency),
                'realized_per_bottle' => $this->money($a['qty'] > 0 ? (int) round($a['real_rev'] / $a['qty']) : 0, $currency),
                'list_revenue' => $this->money($a['list_rev'], $currency),
                'realized_revenue' => $this->money($a['real_rev'], $currency),
                'leakage' => $this->money($leak, $currency),
                'realization_pct' => $a['list_rev'] > 0 ? number_format($a['real_rev'] / $a['list_rev'] * 100, 1, '.', '') : null,
            ];
        }
        usort($rows, fn (array $a, array $b) => $b['leakage']['minor'] <=> $a['leakage']['minor']);

        return [
            'rows' => $rows,
            'totals' => [
                'list_revenue' => $this->money($listTotal, $currency),
                'realized_revenue' => $this->money($realTotal, $currency),
                'leakage' => $this->money($listTotal - $realTotal, $currency),
                'realization_pct' => $listTotal > 0 ? number_format($realTotal / $listTotal * 100, 1, '.', '') : null,
            ],
        ];
    }

    /**
     * @param  Collection<int, Order>  $orders
     * @return list<array<string, mixed>>
     */
    private function histogram(Collection $orders, string $currency): array
    {
        $rows = array_map(fn (array $b) => $b + ['orders' => 0, 'rev' => 0], self::MARGIN_BUCKETS);

        foreach ($orders as $order) {
            $rev = 0;
            $cogs = 0;
            foreach ($order->items as $line) {
                $rev += $line->total->getMinorAmount();
                $cost = $line->cost_per_unit?->getMinorAmount();
                if ($cost !== null) {
                    $cogs += $cost * (int) $line->quantity;
                }
            }
            if ($rev <= 0) {
                continue;
            }
            $margin = ($rev - $cogs) / $rev * 100;
            foreach ($rows as $i => $b) {
                $min = $b['min'];
                $max = $b['max'];
                if (($min === null || $margin >= $min) && ($max === null || $margin < $max)) {
                    $rows[$i]['orders']++;
                    $rows[$i]['rev'] += $rev;
                    break;
                }
            }
        }

        return array_values(array_map(fn (array $b) => [
            'label' => $b['label'],
            'min' => $b['min'],
            'orders' => $b['orders'],
            'revenue' => $this->money($b['rev'], $currency),
        ], $rows));
    }

    private function consignmentRevenue(Carbon $from, Carbon $to): int
    {
        return (int) ConsignmentReportItem::query()
            ->join('consignment_reports', 'consignment_reports.id', '=', 'consignment_report_items.report_id')
            ->where('consignment_reports.kind', ConsignmentReportKind::Sale->value)
            ->whereBetween('consignment_reports.date', [$from, $to])
            ->sum('consignment_report_items.total');
    }

    private function channel(?Customer $customer): string
    {
        if ($customer === null) {
            return 'other';
        }
        if ($customer->is_agency) {
            return 'agency';
        }

        return $customer->customer_type?->channelKey() ?? 'other';
    }

    private function bottles(int $quantity, string $unitType, ?InventoryItem $item): int
    {
        $qty = max(0, $quantity);
        $bpc = max(1, (int) ($item !== null ? $item->bottles_per_case : 12));

        return $unitType === 'cases' ? $qty * $bpc : $qty;
    }

    /** @return array{0: string, 1: string, 2: string} bucket key, ISO date, label */
    private function bucket(Carbon $date, string $granularity): array
    {
        if ($granularity === 'week') {
            $start = $date->copy()->startOfWeek();

            return [$start->format('o-\WW'), $start->toDateString(), 'W'.$start->isoWeek()];
        }
        $start = $date->copy()->startOfDay();

        return [$start->toDateString(), $start->toDateString(), $start->isoFormat('MMM D')];
    }

    /** @return array<string, mixed> */
    private function money(int $minor, string $currency): array
    {
        return Money::fromMinor($minor, $currency)->jsonSerialize();
    }

    /** @return list<string> */
    private function excludedCustomers(): array
    {
        return $this->excluded ??= Customer::statsExcludedIds();
    }

    private function currency(): string
    {
        $currency = $this->tenant->current()?->settings()->first()?->default_currency;

        return $currency ?? CurrencyRegistry::default()->code;
    }
}
