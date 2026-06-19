<?php

declare(strict_types=1);

namespace App\Queries;

use App\Enums\InventoryCategory;
use App\Enums\OrderStatus;
use App\Models\Bottling;
use App\Models\Customer;
use App\Models\InventoryItem;
use App\Models\Order;
use App\Support\Money\CurrencyRegistry;
use App\Support\Money\Money;
use App\Support\OrderCadence;
use App\Tenancy\Contracts\TenantContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Demand forecast for the orders module — a port of the prototype's
 * /api/demand-forecast. Aggregates the last 24 months of (non-consignment,
 * non-excluded-customer) orders into:
 *  - revenue totals (3/6/12m), current-month run-rate, a year-over-year factor;
 *  - a 12-month revenue history + next-3-month and full-year projections;
 *  - per-product volume history, projections and vintage sell-through;
 *  - per-customer cadence and expected next-order timing.
 *
 * Money is minor units (serialized as Money objects); volumes are bottles.
 * Projections lean on last-year-same-month × the YoY factor, and never invent
 * numbers where there's no comparable history (those come back null + flagged).
 */
class DemandForecastQuery
{
    private const OPEN_STATUSES = [
        OrderStatus::Received->value,
        OrderStatus::InProcess->value,
        OrderStatus::ReadyToShip->value,
    ];

    public function __construct(private readonly TenantContext $tenant) {}

    /**
     * @return array<string, mixed>
     */
    public function get(): array
    {
        $currency = $this->currency();
        $now = Carbon::now();
        $startOfMonth = $now->copy()->startOfMonth();
        $year = (int) $now->year;

        $c3 = $now->copy()->subMonths(3);
        $c6 = $now->copy()->subMonths(6);
        $c12 = $now->copy()->subMonths(12);

        $excluded = Customer::statsExcludedIds();

        /** @var Collection<int, Order> $orders */
        $orders = Order::query()
            ->where('is_consignment', false)
            ->whereNotIn('customer_id', $excluded)
            ->where('created_at', '>=', $now->copy()->subMonths(24))
            ->with(['items:id,order_id,inventory_item_id,quantity,unit_type,total'])
            ->get(['id', 'created_at', 'total_amount', 'customer_id', 'status']);

        // Finished, active items keyed by id — the product universe + bottle maths.
        $items = InventoryItem::query()
            ->where('category', InventoryCategory::Finished->value)
            ->where('is_active', true)
            ->get(['id', 'name', 'sku', 'vintage', 'group', 'subcategory', 'unit', 'bottles_per_case', 'current_stock'])
            ->keyBy('id');

        // Bottles produced per item (for vintage sell-through), from bottlings.
        $produced = Bottling::query()
            ->selectRaw('inventory_item_id, SUM(bottle_count) as produced')
            ->groupBy('inventory_item_id')
            ->pluck('produced', 'inventory_item_id');

        // ── Single pass: revenue-by-month, totals, per-product, per-customer ──
        $revenueByMonth = [];          // 'Y-m' => minor
        $totals = $this->emptyWindows();
        $totalCustomers = ['3' => [], '6' => [], '12' => []];
        $prod = [];                    // item id => working aggregate
        $cust = [];                    // customer id => working aggregate

        foreach ($orders as $order) {
            $created = $order->created_at;
            if (! $created instanceof Carbon) {
                continue;
            }
            $mk = $created->format('Y-m');
            $rev = $order->total_amount->getMinorAmount();
            $revenueByMonth[$mk] = ($revenueByMonth[$mk] ?? 0) + $rev;

            $in3 = $created->greaterThanOrEqualTo($c3);
            $in6 = $created->greaterThanOrEqualTo($c6);
            $in12 = $created->greaterThanOrEqualTo($c12);
            $cid = (string) $order->customer_id;

            foreach (['3' => $in3, '6' => $in6, '12' => $in12] as $w => $inWindow) {
                if ($inWindow) {
                    $totals[$w]['order_count']++;
                    $totals[$w]['revenue'] += $rev;
                    $totalCustomers[$w][$cid] = true;
                }
            }

            // Customer aggregate.
            $cust[$cid] ??= ['orders' => 0, 'total' => 0, 'rev12' => 0, 'revYtd' => 0, 'dates' => [], 'last' => null];
            $cust[$cid]['orders']++;
            $cust[$cid]['total'] += $rev;
            $cust[$cid]['dates'][] = $created->copy();
            if ($in12) {
                $cust[$cid]['rev12'] += $rev;
            }
            if ((int) $created->year === $year) {
                $cust[$cid]['revYtd'] += $rev;
            }
            if ($cust[$cid]['last'] === null || $created->greaterThan($cust[$cid]['last'])) {
                $cust[$cid]['last'] = $created->copy();
            }

            $status = (string) $order->status->value;
            foreach ($order->items as $item) {
                $iid = $item->inventory_item_id;
                $itemModel = $iid !== null ? $items->get($iid) : null;
                if (! $itemModel instanceof InventoryItem) {
                    continue;
                }
                $bottles = $this->bottles($item->quantity, (string) $item->unit_type, $itemModel);
                $totals['3']['bottles'] += $in3 ? $bottles : 0;
                $totals['6']['bottles'] += $in6 ? $bottles : 0;
                $totals['12']['bottles'] += $in12 ? $bottles : 0;

                $p = &$prod[$iid];
                $p ??= [
                    'byMonth' => [], 'b3' => 0, 'b6' => 0, 'b12' => 0,
                    'o3' => 0, 'o6' => 0, 'o12' => 0, 'c3' => [], 'c6' => [], 'c12' => [],
                    'last' => null, 'sold' => 0, 'open' => 0,
                ];
                $p['byMonth'][$mk] = ($p['byMonth'][$mk] ?? 0) + $bottles;
                if ($in12) {
                    $p['b12'] += $bottles;
                    $p['o12']++;
                    $p['c12'][$cid] = true;
                }
                if ($in6) {
                    $p['b6'] += $bottles;
                    $p['o6']++;
                    $p['c6'][$cid] = true;
                }
                if ($in3) {
                    $p['b3'] += $bottles;
                    $p['o3']++;
                    $p['c3'][$cid] = true;
                }
                if ($p['last'] === null || $created->greaterThan($p['last'])) {
                    $p['last'] = $created->copy();
                }
                if ($status === OrderStatus::Shipped->value) {
                    $p['sold'] += $bottles;
                } elseif (in_array($status, self::OPEN_STATUSES, true)) {
                    $p['open'] += $bottles;
                }
                unset($p);
            }
        }

        $yoy = $this->yoyFactor($revenueByMonth, $startOfMonth);

        return [
            'generated_at' => $now->toIso8601String(),
            'totals' => [
                'last3m' => $this->windowOut($totals['3'], count($totalCustomers['3']), $currency),
                'last6m' => $this->windowOut($totals['6'], count($totalCustomers['6']), $currency),
                'last12m' => $this->windowOut($totals['12'], count($totalCustomers['12']), $currency),
            ],
            'current_month' => $this->currentMonth($revenueByMonth, $startOfMonth, $yoy, $currency),
            'yoy_factor' => $yoy,
            'revenue_forecast_next_3m' => $this->revenueForecast($revenueByMonth, $startOfMonth, $yoy, $currency),
            'revenue_history_12m' => $this->revenueHistory($revenueByMonth, $startOfMonth, $currency),
            'annual_revenue_projection' => $this->annualRevenue($revenueByMonth, $now, $year, $yoy, $currency),
            'products' => $products = $this->products($prod, $items, $produced, $startOfMonth, $now, $year, $yoy),
            'category_breakdown' => $this->categoryBreakdown($products),
            'customers' => $this->customers($cust, $now, $year, $currency),
        ];
    }

    // ── Revenue-level helpers ────────────────────────────────────────────────

    /** @param array<string, int> $byMonth */
    private function yoyFactor(array $byMonth, Carbon $startOfMonth): ?float
    {
        $recent = 0;
        $lastYear = 0;
        for ($i = 1; $i <= 3; $i++) {
            $recent += $byMonth[$startOfMonth->copy()->subMonths($i)->format('Y-m')] ?? 0;
            $lastYear += $byMonth[$startOfMonth->copy()->subMonths($i + 12)->format('Y-m')] ?? 0;
        }

        return $lastYear > 0 ? $recent / $lastYear : null;
    }

    /**
     * @param  array<string, int>  $byMonth
     * @return array<string, mixed>
     */
    private function currentMonth(array $byMonth, Carbon $startOfMonth, ?float $yoy, string $currency): array
    {
        $key = $startOfMonth->format('Y-m');
        $lyKey = $startOfMonth->copy()->subMonths(12)->format('Y-m');
        $ly = $byMonth[$lyKey] ?? null;
        $projected = ($ly !== null && $yoy !== null) ? (int) round($ly * $yoy) : null;

        return [
            'key' => $key,
            'revenue_so_far' => $this->money($byMonth[$key] ?? 0, $currency),
            'projected_full_month' => $projected !== null ? $this->money($projected, $currency) : null,
            'last_year_same_month' => $ly !== null ? $this->money($ly, $currency) : null,
        ];
    }

    /**
     * @param  array<string, int>  $byMonth
     * @return list<array<string, mixed>>
     */
    private function revenueForecast(array $byMonth, Carbon $startOfMonth, ?float $yoy, string $currency): array
    {
        $out = [];
        for ($i = 0; $i < 3; $i++) {
            $target = $startOfMonth->copy()->addMonths($i);
            $ly = $byMonth[$target->copy()->subMonths(12)->format('Y-m')] ?? null;
            $expected = ($ly !== null && $yoy !== null) ? (int) round($ly * $yoy) : null;
            $out[] = [
                'month' => $target->format('Y-m'),
                'last_year_revenue' => $ly !== null ? $this->money($ly, $currency) : null,
                'expected' => $expected !== null ? $this->money($expected, $currency) : null,
            ];
        }

        return $out;
    }

    /**
     * @param  array<string, int>  $byMonth
     * @return list<array<string, mixed>>
     */
    private function revenueHistory(array $byMonth, Carbon $startOfMonth, string $currency): array
    {
        $out = [];
        for ($i = 11; $i >= 0; $i--) {
            $d = $startOfMonth->copy()->subMonths($i);
            $out[] = [
                'month' => $d->format('Y-m'),
                'revenue' => $this->money($byMonth[$d->format('Y-m')] ?? 0, $currency),
                'last_year' => $this->money($byMonth[$d->copy()->subMonths(12)->format('Y-m')] ?? 0, $currency),
            ];
        }

        return $out;
    }

    /**
     * Run the remaining-months projection over a per-month last-year series.
     * Shared by annual revenue and per-product annual bottle projections.
     *
     * @param  array<string, int|float>  $byMonth
     * @return array{remaining: float, hasGap: bool}
     */
    private function projectRemainder(array $byMonth, Carbon $now, int $year, ?float $yoy): array
    {
        $remaining = 0.0;
        $hasGap = false;
        for ($m = (int) $now->month - 1; $m <= 11; $m++) {
            $target = Carbon::parse(sprintf('%04d-%02d-01', $year, $m + 1));
            $ly = $byMonth[$target->copy()->subMonths(12)->format('Y-m')] ?? 0;
            $reliable = $ly > 0 && $yoy !== null;
            $expected = $reliable ? $ly * $yoy : 0;
            if ($m === (int) $now->month - 1) {
                $already = $byMonth[$target->format('Y-m')] ?? 0;
                $remaining += max(0, $expected - $already);
            } else {
                $remaining += $expected;
            }
            if (! $reliable) {
                $hasGap = true;
            }
        }

        return ['remaining' => $remaining, 'hasGap' => $hasGap];
    }

    /**
     * @param  array<string, int>  $byMonth
     * @return array<string, mixed>
     */
    private function annualRevenue(array $byMonth, Carbon $now, int $year, ?float $yoy, string $currency): array
    {
        $ytd = 0;
        foreach ($byMonth as $mk => $minor) {
            if ((int) substr($mk, 0, 4) === $year) {
                $ytd += $minor;
            }
        }
        $proj = $this->projectRemainder($byMonth, $now, $year, $yoy);
        $remaining = (int) round($proj['remaining']);

        return [
            'year' => $year,
            'ytd' => $this->money($ytd, $currency),
            'projected_remaining' => $this->money($remaining, $currency),
            'projected_total' => $this->money($ytd + $remaining, $currency),
            'has_history_gap' => $proj['hasGap'],
        ];
    }

    // ── Product-level ─────────────────────────────────────────────────────────

    /**
     * @param  array<string, array<string, mixed>>  $prod
     * @param  Collection<string, InventoryItem>  $items
     * @param  Collection<string, mixed>  $produced
     * @return list<array<string, mixed>>
     */
    private function products(array $prod, $items, $produced, Carbon $startOfMonth, Carbon $now, int $year, ?float $yoy): array
    {
        $rows = [];
        foreach ($items as $id => $item) {
            $p = $prod[$id] ?? null;
            $byMonth = $p['byMonth'] ?? [];

            $bpc = max(1, (int) $item->bottles_per_case);
            $isCase = in_array(strtolower((string) $item->unit), ['case', 'cases'], true);
            $remaining = (int) round((float) $item->current_stock * ($isCase ? $bpc : 1));

            $sold = (int) ($p['sold'] ?? 0);
            $open = (int) ($p['open'] ?? 0);
            $totalProduced = (int) ($produced[$id] ?? 0);
            if ($totalProduced <= 0 && ($remaining + $sold + $open) > 0) {
                $totalProduced = $remaining + $sold + $open;
            }

            // Drop items with neither history nor stock nor production — noise.
            if ($p === null && $totalProduced <= 0 && $remaining <= 0) {
                continue;
            }

            $annual = $this->productAnnual($byMonth, $now, $year, $yoy);
            $ytdBottles = 0;
            foreach ($byMonth as $mk => $b) {
                if ((int) substr((string) $mk, 0, 4) === $year) {
                    $ytdBottles += $b;
                }
            }

            $rows[] = [
                'id' => (string) $id,
                'name' => $item->name,
                'sku' => $item->sku,
                'vintage' => $item->vintage,
                'group' => $item->group,
                'subcategory' => $item->subcategory,
                'unit' => $item->unit,
                'current_stock' => (float) $item->current_stock,
                'bottles_per_case' => $bpc,
                'last3m' => ['bottles' => (int) ($p['b3'] ?? 0), 'orders' => (int) ($p['o3'] ?? 0), 'customers' => count($p['c3'] ?? [])],
                'last6m' => ['bottles' => (int) ($p['b6'] ?? 0), 'orders' => (int) ($p['o6'] ?? 0), 'customers' => count($p['c6'] ?? [])],
                'last12m' => ['bottles' => (int) ($p['b12'] ?? 0), 'orders' => (int) ($p['o12'] ?? 0), 'customers' => count($p['c12'] ?? [])],
                'last_sold_date' => ($p['last'] ?? null) instanceof Carbon ? $p['last']->toIso8601String() : null,
                'biggest_month' => $this->biggestMonth($byMonth, $startOfMonth),
                'expected_next_3m' => $this->productForecast($byMonth, $startOfMonth, $yoy),
                'history_12m' => $this->productHistory($byMonth, $startOfMonth),
                'annual_projection' => [
                    'ytd_bottles' => (int) round($ytdBottles),
                    'projected_remaining_bottles' => (int) round($annual['remaining']),
                    'projected_total_bottles' => (int) round($ytdBottles + $annual['remaining']),
                    'has_history_gap' => $annual['hasGap'],
                ],
                'total_produced' => $totalProduced,
                'sold' => $sold,
                'to_be_sold' => $open,
                'remaining' => $remaining,
            ];
        }

        // Most active first (by 12-month volume), matching the prototype's intent.
        usort($rows, fn (array $a, array $b) => $b['last12m']['bottles'] <=> $a['last12m']['bottles']);

        return $rows;
    }

    /**
     * @param  array<string, int>  $byMonth
     * @return array{remaining: float, hasGap: bool}
     */
    private function productAnnual(array $byMonth, Carbon $now, int $year, ?float $yoy): array
    {
        return $this->projectRemainder($byMonth, $now, $year, $yoy);
    }

    /**
     * @param  array<string, int>  $byMonth
     * @return array{month: string, bottles: int}|null
     */
    private function biggestMonth(array $byMonth, Carbon $startOfMonth): ?array
    {
        $best = null;
        for ($i = 11; $i >= 0; $i--) {
            $mk = $startOfMonth->copy()->subMonths($i)->format('Y-m');
            $b = $byMonth[$mk] ?? 0;
            if ($b > 0 && ($best === null || $b > $best['bottles'])) {
                $best = ['month' => $mk, 'bottles' => (int) $b];
            }
        }

        return $best;
    }

    /**
     * @param  array<string, int>  $byMonth
     * @return list<array<string, mixed>>
     */
    private function productForecast(array $byMonth, Carbon $startOfMonth, ?float $yoy): array
    {
        $out = [];
        for ($i = 0; $i < 3; $i++) {
            $target = $startOfMonth->copy()->addMonths($i);
            $ly = $byMonth[$target->copy()->subMonths(12)->format('Y-m')] ?? 0;
            $expected = ($ly > 0 && $yoy !== null) ? (int) round($ly * $yoy) : null;
            $out[] = ['month' => $target->format('Y-m'), 'last_year_bottles' => (int) $ly, 'expected' => $expected];
        }

        return $out;
    }

    /**
     * @param  array<string, int>  $byMonth
     * @return list<array{month: string, bottles: int}>
     */
    private function productHistory(array $byMonth, Carbon $startOfMonth): array
    {
        $out = [];
        for ($i = 11; $i >= 0; $i--) {
            $mk = $startOfMonth->copy()->subMonths($i)->format('Y-m');
            $out[] = ['month' => $mk, 'bottles' => (int) ($byMonth[$mk] ?? 0)];
        }

        return $out;
    }

    /**
     * Roll product rows up into group → subcategory bottle aggregates.
     *
     * @param  list<array<string, mixed>>  $products
     * @return list<array<string, mixed>>
     */
    private function categoryBreakdown(array $products): array
    {
        $groups = [];
        foreach ($products as $p) {
            $g = (string) ($p['group'] ?? '—');
            $sc = $p['subcategory'];
            $scKey = $sc ?? '—';
            $groups[$g] ??= ['group' => $g, 'products' => 0, 'b3' => 0, 'b6' => 0, 'b12' => 0, 'ytd' => 0, 'rem' => 0, 'tot' => 0, 'gap' => false, 'subs' => []];
            $groups[$g]['subs'][$scKey] ??= ['subcategory' => $sc, 'products' => 0, 'b3' => 0, 'b6' => 0, 'b12' => 0, 'ytd' => 0, 'rem' => 0, 'tot' => 0, 'gap' => false];

            foreach ([&$groups[$g], &$groups[$g]['subs'][$scKey]] as &$bucket) {
                $bucket['products']++;
                $bucket['b3'] += $p['last3m']['bottles'];
                $bucket['b6'] += $p['last6m']['bottles'];
                $bucket['b12'] += $p['last12m']['bottles'];
                $bucket['ytd'] += $p['annual_projection']['ytd_bottles'];
                $bucket['rem'] += $p['annual_projection']['projected_remaining_bottles'];
                $bucket['tot'] += $p['annual_projection']['projected_total_bottles'];
                if ($p['annual_projection']['has_history_gap']) {
                    $bucket['gap'] = true;
                }
            }
            unset($bucket);
        }

        $out = array_map(function (array $g): array {
            $subs = array_values(array_map(fn (array $s) => $this->breakdownRow($s, true), $g['subs']));
            usort($subs, fn (array $a, array $b) => $b['projected_total_bottles'] <=> $a['projected_total_bottles']);

            return $this->breakdownRow($g, false) + ['subcategories' => $subs];
        }, array_values($groups));

        usort($out, fn (array $a, array $b) => $b['projected_total_bottles'] <=> $a['projected_total_bottles']);

        return $out;
    }

    /**
     * @param  array<string, mixed>  $b
     * @return array<string, mixed>
     */
    private function breakdownRow(array $b, bool $isSub): array
    {
        $row = [
            'products' => $b['products'],
            'last3m_bottles' => $b['b3'],
            'last6m_bottles' => $b['b6'],
            'last12m_bottles' => $b['b12'],
            'ytd_bottles' => $b['ytd'],
            'projected_remaining_bottles' => $b['rem'],
            'projected_total_bottles' => $b['tot'],
            'has_history_gap' => $b['gap'],
        ];

        return $isSub ? ['subcategory' => $b['subcategory']] + $row : ['group' => $b['group']] + $row;
    }

    // ── Customer-level ─────────────────────────────────────────────────────────

    /**
     * @param  array<string, array<string, mixed>>  $cust
     * @return list<array<string, mixed>>
     */
    private function customers(array $cust, Carbon $now, int $year, string $currency): array
    {
        $names = Customer::query()->whereIn('id', array_keys($cust))->pluck('company_name', 'id');

        $rows = [];
        foreach ($cust as $id => $c) {
            /** @var list<Carbon> $dates */
            $dates = $c['dates'];
            usort($dates, fn (Carbon $a, Carbon $b) => $a <=> $b);
            $median = OrderCadence::medianGapDays($dates);
            /** @var Carbon|null $last */
            $last = $c['last'];
            $expected = ($median > 0 && $last instanceof Carbon)
                ? $last->copy()->addDays((int) round($median))
                : null;

            $rows[] = [
                'id' => (string) $id,
                'name' => $names[$id] ?? null,
                'order_count' => (int) $c['orders'],
                'total_value' => $this->money((int) $c['total'], $currency),
                'revenue_last_12m' => $this->money((int) $c['rev12'], $currency),
                'revenue_ytd' => $this->money((int) $c['revYtd'], $currency),
                'median_gap_days' => $median > 0 ? (int) round($median) : null,
                'days_since_last_order' => $last instanceof Carbon ? (int) floor($last->diffInDays($now)) : null,
                'last_order_date' => $last instanceof Carbon ? $last->toIso8601String() : null,
                'expected_by_date' => $expected?->toIso8601String(),
            ];
        }

        usort($rows, fn (array $a, array $b) => $b['revenue_last_12m']['minor'] <=> $a['revenue_last_12m']['minor']);

        return $rows;
    }

    // ── Small helpers ──────────────────────────────────────────────────────────

    /** @return array{3: array<string,int>, 6: array<string,int>, 12: array<string,int>} */
    private function emptyWindows(): array
    {
        $blank = ['order_count' => 0, 'bottles' => 0, 'revenue' => 0];

        return ['3' => $blank, '6' => $blank, '12' => $blank];
    }

    /**
     * @param  array<string, int>  $w
     * @return array<string, mixed>
     */
    private function windowOut(array $w, int $customers, string $currency): array
    {
        return [
            'order_count' => $w['order_count'],
            'customers' => $customers,
            'bottles' => $w['bottles'],
            'revenue' => $this->money($w['revenue'], $currency),
        ];
    }

    private function bottles(int $quantity, string $unitType, InventoryItem $item): int
    {
        $qty = max(0, $quantity);
        $bpc = max(1, (int) $item->bottles_per_case);

        return $unitType === 'cases' ? $qty * $bpc : $qty;
    }

    /** @return array<string, mixed> */
    private function money(int $minor, string $currency): array
    {
        return Money::fromMinor($minor, $currency)->jsonSerialize();
    }

    private function currency(): string
    {
        $currency = $this->tenant->current()?->settings()->first()?->default_currency;

        return $currency ?? CurrencyRegistry::default()->code;
    }
}
