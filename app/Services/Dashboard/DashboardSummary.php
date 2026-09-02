<?php

declare(strict_types=1);

namespace App\Services\Dashboard;

use App\Enums\CostCategory;
use App\Enums\CustomerType;
use App\Enums\InflowStatus;
use App\Enums\OrderStatus;
use App\Enums\TaskStatus;
use App\Models\Cost;
use App\Models\Customer;
use App\Models\Inflow;
use App\Models\InventoryItem;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\WorkOrder;
use App\Queries\InventoryAnalyticsQuery;
use App\Support\Money\CurrencyRegistry;
use App\Support\Money\Money;
use App\Tenancy\Contracts\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The aggregated dashboard payload, computed from real module data (orders,
 * revenue, A/R, inventory, tasks). Transport-agnostic: the same call backs the
 * API and any future server-rendered page. Money values are integer minor units.
 */
class DashboardSummary
{
    private const RANGE_DAYS = ['7D' => 7, '30D' => 30, '90D' => 90, '1Y' => 365, 'ALL' => 540];

    /** @var array<string, string> OrderStatus value → frontend key. */
    private const STATUS_KEY = [
        'RECEIVED' => 'received',
        'IN_PROCESS' => 'inProcess',
        'READY_TO_SHIP' => 'readyToShip',
        'SHIPPED' => 'shipped',
    ];

    /** @var list<string>|null Customers excluded from stats (memoized). */
    private ?array $excluded = null;

    public function __construct(
        private readonly InventoryAnalyticsQuery $analytics,
        private readonly TenantContext $tenant,
    ) {}

    /** @return list<string> */
    private function excludedCustomers(): array
    {
        return $this->excluded ??= Customer::statsExcludedIds();
    }

    /**
     * Build the dashboard payload for a selected window. `$period` is the new
     * preset token (today/mtd/ytd/…); `$range` is the legacy day-count token
     * (7D/30D/…) kept for backward compatibility. `$from`/`$to` carry a custom
     * date range when `$period === 'custom'`.
     *
     * @return array<string, mixed>
     */
    public function build(?string $period = null, ?string $range = null, ?string $from = null, ?string $to = null): array
    {
        [$since, $until, $token] = $this->resolveWindow($period, $range, $from, $to);

        /** @var Collection<int, Order> $orders */
        $orders = Order::query()
            ->where('is_consignment', false)
            ->whereNotIn('customer_id', $this->excludedCustomers())
            ->when($since !== null, fn ($q) => $q->where('created_at', '>=', $since))
            ->where('created_at', '<=', $until)
            ->get(['id', 'order_number', 'created_at', 'total_amount', 'status', 'customer_id']);

        // Bucket the window into at most 30 points for the trend charts.
        $spanStart = $since ?? $until->copy()->subDays(self::RANGE_DAYS['ALL']);
        $days = max(1, (int) $spanStart->diffInDays($until));
        $points = min($days, 30);
        $step = max(1, intdiv($days, $points));
        $orderCounts = array_fill(0, $points, 0);
        $revenueBuckets = array_fill(0, $points, 0);

        foreach ($orders as $order) {
            $bucket = intdiv((int) ($order->created_at?->diffInDays($until) ?? 0), $step);
            if ($bucket >= 0 && $bucket < $points) {
                $orderCounts[$bucket]++;
                $revenueBuckets[$bucket] += $order->total_amount->getMinorAmount();
            }
        }

        return [
            'range' => $token,
            'currency' => $this->currency(),
            'revenue_summary' => $this->revenueSummary(),
            'revenue_by_channel' => $this->revenueByChannel($since, $until),
            'key_ratios' => $this->keyRatios($orders, $since, $until),
            'stats' => [
                'total_orders' => $orders->count(),
                'customers' => Customer::query()->where('is_active', true)
                    ->where('exclude_from_stats', false)->count(),
                'revenue' => (int) $orders->sum(fn (Order $o) => $o->total_amount->getMinorAmount()),
                'low_stock' => $this->analytics->lowStockCount(),
                'outstanding_ar' => $this->outstandingAr(),
                'tasks_overdue' => $this->overdueTasks(),
            ],
            'orders' => $this->series(array_values($orderCounts), $step, $until),
            'revenue' => $this->series(array_values($revenueBuckets), $step, $until),
            'order_status' => $this->orderStatus($orders),
            'top_products' => $this->topProducts($since, $until),
            'stock_watch' => $this->analytics->stockWatch(6),
            'recent_orders' => $this->recentOrders(),
        ];
    }

    /**
     * Realized revenue for the always-on summary cards: today, month-to-date,
     * year-to-date (each vs. the same window one year earlier) and all-time
     * total. Independent of the selected period. Money is integer minor units.
     *
     * @return array<string, array{current: int, previous: int|null}>
     */
    private function revenueSummary(): array
    {
        $now = Carbon::now();
        $lastYear = $now->copy()->subYear();

        return [
            'today' => [
                'current' => $this->revenueBetween($now->copy()->startOfDay(), $now),
                'previous' => $this->revenueBetween($lastYear->copy()->startOfDay(), $lastYear),
            ],
            'mtd' => [
                'current' => $this->revenueBetween($now->copy()->startOfMonth(), $now),
                'previous' => $this->revenueBetween($lastYear->copy()->startOfMonth(), $lastYear),
            ],
            'ytd' => [
                'current' => $this->revenueBetween($now->copy()->startOfYear(), $now),
                'previous' => $this->revenueBetween($lastYear->copy()->startOfYear(), $lastYear),
            ],
            'total' => [
                'current' => $this->revenueBetween(null, null),
                'previous' => null,
            ],
        ];
    }

    /**
     * Revenue split by customer sales channel for the selected window, each with
     * the preceding equal-length window so the UI can show a trend. Money is
     * integer minor units; `previous` is null when there's no comparable window.
     *
     * @return array<string, array{current: int, previous: int|null}>
     */
    private function revenueByChannel(?Carbon $since, Carbon $until): array
    {
        $current = $this->channelTotals($since, $until);

        // Previous comparable window: same length immediately before `since`.
        $previous = null;
        if ($since !== null) {
            $length = $since->diffInSeconds($until);
            $previous = $this->channelTotals($since->copy()->subSeconds((int) $length), $since->copy());
        }

        $out = [];
        foreach (['wholesale', 'retail', 'agency', 'shipshop', 'other', 'total'] as $key) {
            $out[$key] = ['current' => $current[$key], 'previous' => $previous[$key] ?? null];
        }

        return $out;
    }

    /**
     * Flat channel totals (minor units) for one window: non-consignment orders,
     * excluding stats-excluded customers, bucketed by the customer's channel.
     *
     * @return array{wholesale: int, retail: int, agency: int, shipshop: int, other: int, total: int}
     */
    private function channelTotals(?Carbon $since, Carbon $until): array
    {
        /** @var Collection<int, Order> $orders */
        $orders = Order::query()
            ->where('is_consignment', false)
            ->whereNotIn('customer_id', $this->excludedCustomers())
            ->when($since !== null, fn (Builder $q) => $q->where('created_at', '>=', $since))
            ->where('created_at', '<=', $until)
            ->get(['total_amount', 'customer_id']);

        // pluck() bypasses model casts, so values come back as raw strings.
        $types = Customer::query()
            ->whereIn('id', $orders->pluck('customer_id')->filter()->unique()->all())
            ->pluck('customer_type', 'id');

        $channels = ['wholesale' => 0, 'retail' => 0, 'agency' => 0, 'shipshop' => 0, 'other' => 0];
        foreach ($orders as $order) {
            $raw = $types->get($order->customer_id);
            $type = $raw instanceof CustomerType ? $raw : (is_string($raw) ? CustomerType::tryFrom($raw) : null);
            $key = $type?->channelKey() ?? 'other';
            $channels[array_key_exists($key, $channels) ? $key : 'other'] += $order->total_amount->getMinorAmount();
        }

        return [
            'wholesale' => $channels['wholesale'],
            'retail' => $channels['retail'],
            'agency' => $channels['agency'],
            'shipshop' => $channels['shipshop'],
            'other' => $channels['other'],
            'total' => array_sum($channels),
        ];
    }

    /**
     * Headline financial ratios for the selected window, mirroring the prototype's
     * getKeyRatios. Denominator is the channel total. Each ratio is null when the
     * underlying input is missing (e.g. no payroll imported) so the tile renders
     * "—" rather than a misleading green 0%. Money values are integer minor units.
     *
     * @param  Collection<int, Order>  $orders  windowed, non-consignment, non-excluded
     * @return array<string, mixed>
     */
    private function keyRatios(Collection $orders, ?Carbon $since, Carbon $until): array
    {
        $currency = $this->currency();
        $channels = $this->channelTotals($since, $until);
        $totalRevenue = $channels['total'];
        $orderCount = $orders->count();
        // DTC = retail (the rebuild has no separate hospitality channel).
        $dtc = $channels['retail'];

        // Costs in the window, excluding stats-excluded suppliers.
        $costBase = fn (): Builder => Cost::query()
            ->when($since !== null, fn (Builder $q) => $q->where('date', '>=', $since))
            ->where('date', '<=', $until)
            ->whereDoesntHave('supplier', fn (Builder $q) => $q->where('exclude_from_stats', true));
        $totalCosts = (int) $costBase()->sum('total_amount');
        $salary = (int) $costBase()->whereRaw('LOWER(category) = ?', [CostCategory::Salary->sqlLower()])->sum('total_amount');
        $marketing = (int) $costBase()->whereRaw('LOWER(category) = ?', [CostCategory::Marketing->sqlLower()])->sum('total_amount');
        $headcount = (int) $costBase()->whereRaw('LOWER(category) = ?', [CostCategory::Salary->sqlLower()])->distinct()->count('description');

        $cogs = $this->shippedCogs($since, $until);
        $bottlesSold = $this->bottlesSold($since, $until);
        $stockBottles = $this->stockOnHandBottles();

        $hasSalary = $salary > 0;
        $hasMarketing = $marketing > 0;
        $operatingReliable = $totalCosts > 0 && $hasSalary && $hasMarketing;
        $round1 = fn (float $v): float => round($v, 1);

        return [
            'dtc_revenue_pct' => $totalRevenue > 0 ? $round1($dtc / $totalRevenue * 100) : null,
            'operating_margin_pct' => $totalRevenue > 0 && $operatingReliable
                ? $round1(($totalRevenue - $totalCosts) / $totalRevenue * 100)
                : null,
            'employee_cost_pct' => $totalRevenue > 0 && $hasSalary
                ? $round1($salary / $totalRevenue * 100)
                : null,
            'marketing_cost_pct' => $totalRevenue > 0 && $hasMarketing
                ? $round1($marketing / $totalRevenue * 100)
                : null,
            'cogs_pct' => $totalRevenue > 0 && $cogs > 0 ? $round1($cogs / $totalRevenue * 100) : null,
            'cogs_amount' => $cogs > 0 ? Money::fromMinor($cogs, $currency)->jsonSerialize() : null,
            'revenue_per_employee' => $headcount > 0
                ? Money::fromMinor((int) round($totalRevenue / $headcount), $currency)->jsonSerialize()
                : null,
            'avg_order_value' => $orderCount > 0
                ? Money::fromMinor((int) round($channels['wholesale'] / $orderCount), $currency)->jsonSerialize()
                : null,
            'inventory_turnover' => $stockBottles > 0 ? $round1($bottlesSold / $stockBottles) : null,
        ];
    }

    /** COGS of goods shipped in the window: snapshot cost × qty, plus freight we pay. */
    private function shippedCogs(?Carbon $since, Carbon $until): int
    {
        /** @var Collection<int, Order> $shipped */
        $shipped = Order::query()
            ->where('status', OrderStatus::Shipped->value)
            ->where('is_consignment', false)
            ->whereNotIn('customer_id', $this->excludedCustomers())
            ->when($since !== null, fn (Builder $q) => $q->where('created_at', '>=', $since))
            ->where('created_at', '<=', $until)
            ->with('items')
            ->get();

        $cogs = 0;
        foreach ($shipped as $order) {
            foreach ($order->items as $item) {
                if ($item->cost_per_unit !== null) {
                    $cogs += $item->cost_per_unit->getMinorAmount() * $item->quantity;
                }
            }
            if ($order->shipping_paid_by_us && $order->shipping_cost !== null) {
                $cogs += $order->shipping_cost->getMinorAmount();
            }
        }

        return $cogs;
    }

    /** Bottles sold in the window (catalog lines only; cases → bottles). */
    private function bottlesSold(?Carbon $since, Carbon $until): int
    {
        $lines = OrderItem::query()
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->leftJoin('inventory_items', 'inventory_items.id', '=', 'order_items.inventory_item_id')
            ->where('orders.is_consignment', false)
            ->whereNotIn('orders.customer_id', $this->excludedCustomers())
            ->when($since !== null, fn (Builder $q) => $q->where('orders.created_at', '>=', $since))
            ->where('orders.created_at', '<=', $until)
            ->whereNotNull('order_items.inventory_item_id')
            ->get(['order_items.quantity', 'order_items.unit_type', 'inventory_items.bottles_per_case']);

        $total = 0;
        foreach ($lines as $line) {
            $qty = (int) $line->quantity;
            $bpc = max(1, (int) ($line->bottles_per_case ?? 12));
            $total += $line->unit_type === 'cases' ? $qty * $bpc : $qty;
        }

        return $total;
    }

    /** Stock on hand across active, for-sale finished items, normalised to bottles. */
    private function stockOnHandBottles(): int
    {
        $items = InventoryItem::query()
            ->where('is_active', true)
            ->where('category', 'FINISHED')
            ->where('is_for_sale', true)
            ->get(['current_stock', 'unit', 'bottles_per_case']);

        $total = 0;
        foreach ($items as $item) {
            $bpc = max(1, (int) $item->bottles_per_case);
            $isCase = in_array(strtolower((string) $item->unit), ['case', 'cases'], true);
            $total += (int) round((float) $item->current_stock * ($isCase ? $bpc : 1));
        }

        return $total;
    }

    /** Sum of non-consignment order revenue (minor units) in an optional window. */
    private function revenueBetween(?Carbon $since, ?Carbon $until): int
    {
        return (int) Order::query()
            ->where('is_consignment', false)
            ->whereNotIn('customer_id', $this->excludedCustomers())
            ->when($since !== null, fn ($q) => $q->where('created_at', '>=', $since))
            ->when($until !== null, fn ($q) => $q->where('created_at', '<=', $until))
            ->sum('total_amount');
    }

    /**
     * Resolve the selected window to [since (nullable for all-time), until, token].
     *
     * @return array{0: ?Carbon, 1: Carbon, 2: string}
     */
    private function resolveWindow(?string $period, ?string $range, ?string $from, ?string $to): array
    {
        $now = Carbon::now();

        $window = $this->periodWindow($period, $from, $to, $now);
        if ($window !== null) {
            return $window;
        }

        // Legacy day-count range param (e.g. 30D); invalid values fall back to 30D.
        if ($range !== null) {
            $token = isset(self::RANGE_DAYS[$range]) ? $range : '30D';

            return [$now->copy()->subDays(self::RANGE_DAYS[$token]), $now->copy(), $token];
        }

        return [$now->copy()->startOfMonth(), $now->copy(), 'mtd'];
    }

    /**
     * @return array{0: ?Carbon, 1: Carbon, 2: string}|null
     */
    private function periodWindow(?string $period, ?string $from, ?string $to, Carbon $now): ?array
    {
        return match ($period) {
            'today' => [$now->copy()->startOfDay(), $now->copy(), 'today'],
            'yesterday' => [$now->copy()->subDay()->startOfDay(), $now->copy()->subDay()->endOfDay(), 'yesterday'],
            // 'week' backs the design's "This Week" tab (Figma 208:5577).
            'week' => [$now->copy()->startOfWeek(), $now->copy(), 'week'],
            'mtd' => [$now->copy()->startOfMonth(), $now->copy(), 'mtd'],
            'qtd' => [$now->copy()->startOfQuarter(), $now->copy(), 'qtd'],
            'ytd' => [$now->copy()->startOfYear(), $now->copy(), 'ytd'],
            'month' => [$now->copy()->startOfMonth(), $now->copy()->endOfMonth(), 'month'],
            'last-month' => [$now->copy()->subMonthNoOverflow()->startOfMonth(), $now->copy()->subMonthNoOverflow()->endOfMonth(), 'last-month'],
            'last-year' => [$now->copy()->subYear()->startOfYear(), $now->copy()->subYear()->endOfYear(), 'last-year'],
            'all' => [null, $now->copy(), 'all'],
            'custom' => $this->customWindow($from, $to, $now),
            default => null,
        };
    }

    /**
     * @return array{0: ?Carbon, 1: Carbon, 2: string}
     */
    private function customWindow(?string $from, ?string $to, Carbon $now): array
    {
        $since = rescue(fn () => $from !== null ? Carbon::parse($from)->startOfDay() : null, null, false);
        $until = rescue(fn () => $to !== null ? Carbon::parse($to)->endOfDay() : null, null, false);

        return [
            $since ?? $now->copy()->startOfMonth(),
            $until ?? $now->copy(),
            'custom',
        ];
    }

    /**
     * @param  list<int>  $buckets  newest-first by bucket index
     * @return list<array{label: string, value: int}>
     */
    private function series(array $buckets, int $step, Carbon $until): array
    {
        $out = [];
        for ($i = count($buckets) - 1; $i >= 0; $i--) {
            $out[] = ['label' => $until->copy()->subDays($i * $step)->format('M j'), 'value' => $buckets[$i]];
        }

        return $out;
    }

    /**
     * @param  Collection<int, Order>  $orders
     * @return list<array{key: string, value: int}>
     */
    private function orderStatus($orders): array
    {
        $counts = $orders->countBy(fn (Order $o) => $o->status->value);

        return array_map(fn (OrderStatus $status) => [
            'key' => self::STATUS_KEY[$status->value],
            'value' => (int) $counts->get($status->value, 0),
        ], OrderStatus::cases());
    }

    /**
     * @return list<array{name: string, value: int}>
     */
    private function topProducts(?Carbon $since, Carbon $until): array
    {
        $rows = OrderItem::query()
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->where('orders.is_consignment', false)
            ->whereNotIn('orders.customer_id', $this->excludedCustomers())
            ->when($since !== null, fn ($q) => $q->where('orders.created_at', '>=', $since))
            ->where('orders.created_at', '<=', $until)
            ->whereNotNull('order_items.inventory_item_id')
            ->groupBy('order_items.inventory_item_id')
            ->orderByDesc('rev')
            ->limit(5)
            ->get([DB::raw('order_items.inventory_item_id as item_id'), DB::raw('SUM(order_items.total) as rev')]);

        $names = InventoryItem::query()->whereIn('id', $rows->pluck('item_id'))->pluck('name', 'id');

        return array_values($rows->map(fn (OrderItem $r) => [
            'name' => (string) ($names[$r->getAttribute('item_id')] ?? ''),
            'value' => (int) $r->getAttribute('rev'),
        ])->all());
    }

    /**
     * @return list<array{id: string, order_number: string, customer: string, created_by: ?string, items: list<array{name: ?string, quantity: mixed, unit_type: string}>, total: int, status: string, date: string}>
     */
    private function recentOrders(): array
    {
        $rows = Order::query()
            ->with(['customer', 'createdBy', 'items.inventoryItem'])
            ->where('is_consignment', false)
            ->whereNotIn('customer_id', $this->excludedCustomers())
            ->orderByDesc('created_at')
            ->limit(5)
            ->get()
            ->map(function (Order $o): array {
                $customer = $o->customer;
                $creator = $o->createdBy;

                return [
                    'id' => $o->getKey(),
                    'order_number' => $o->order_number,
                    'customer' => $customer instanceof Customer ? $customer->company_name : '',
                    'created_by' => $creator !== null ? trim($creator->first_name.' '.$creator->last_name) : null,
                    'items' => array_values($o->items->map(function (OrderItem $it): array {
                        $product = $it->inventoryItem;

                        return [
                            'name' => $product instanceof InventoryItem ? $product->name : $it->custom_description,
                            'quantity' => $it->quantity,
                            'unit_type' => $it->unit_type,
                        ];
                    })->all()),
                    'total' => $o->total_amount->getMinorAmount(),
                    'status' => self::STATUS_KEY[$o->status->value],
                    'date' => $o->created_at?->format('M j') ?? '',
                ];
            })->all();

        return array_values($rows);
    }

    /** Total unpaid order balances (order revenue minus received inflows). */
    private function outstandingAr(): int
    {
        $excluded = $this->excludedCustomers();
        $billed = (int) Order::query()
            ->where('is_consignment', false)
            ->whereNotIn('customer_id', $excluded)
            ->sum('total_amount');
        $received = (int) Inflow::query()
            ->whereNotNull('order_id')
            ->where(fn ($q) => $q->whereNull('customer_id')->orWhereNotIn('customer_id', $excluded))
            ->where('status', InflowStatus::Received->value)
            ->sum(DB::raw('CASE WHEN is_credit_note THEN -amount ELSE amount END'));

        return max(0, $billed - $received);
    }

    private function overdueTasks(): int
    {
        return WorkOrder::query()
            ->where('status', '!=', TaskStatus::Done)
            ->whereNotNull('due_date')
            ->where('due_date', '<', Carbon::now())
            ->count();
    }

    private function currency(): string
    {
        return $this->tenant->current()?->settings->default_currency ?? CurrencyRegistry::default()->code;
    }
}
