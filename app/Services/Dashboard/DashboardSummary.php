<?php

declare(strict_types=1);

namespace App\Services\Dashboard;

use App\Enums\CustomerType;
use App\Enums\InflowStatus;
use App\Enums\OrderStatus;
use App\Enums\TaskStatus;
use App\Models\Customer;
use App\Models\Inflow;
use App\Models\InventoryItem;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\WorkOrder;
use App\Queries\InventoryAnalyticsQuery;
use App\Support\Money\CurrencyRegistry;
use App\Tenancy\Contracts\TenantContext;
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

    public function __construct(
        private readonly InventoryAnalyticsQuery $analytics,
        private readonly TenantContext $tenant,
    ) {}

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
            'revenue_by_channel' => $this->revenueByChannel($orders),
            'stats' => [
                'total_orders' => $orders->count(),
                'customers' => Customer::query()->where('is_active', true)->count(),
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
     * Revenue for the selected window split by the customer's sales channel
     * (customer_type). Orders without a customer, or whose type is unset, fall
     * under "other". Money is integer minor units.
     *
     * @param  Collection<int, Order>  $orders  windowed, non-consignment
     * @return array{wholesale: int, retail: int, agency: int, shipshop: int, other: int, total: int}
     */
    private function revenueByChannel(Collection $orders): array
    {
        // pluck() bypasses model casts, so values come back as raw strings.
        $types = Customer::query()
            ->whereIn('id', $orders->pluck('customer_id')->filter()->unique()->all())
            ->pluck('customer_type', 'id');

        $channels = ['wholesale' => 0, 'retail' => 0, 'agency' => 0, 'shipshop' => 0, 'other' => 0];

        foreach ($orders as $order) {
            $raw = $order->customer_id !== null ? $types->get($order->customer_id) : null;
            $type = $raw instanceof CustomerType ? $raw : (is_string($raw) ? CustomerType::tryFrom($raw) : null);
            $channels[$type?->channelKey() ?? 'other'] += $order->total_amount->getMinorAmount();
        }

        $channels['total'] = array_sum($channels);

        return $channels;
    }

    /** Sum of non-consignment order revenue (minor units) in an optional window. */
    private function revenueBetween(?Carbon $since, ?Carbon $until): int
    {
        return (int) Order::query()
            ->where('is_consignment', false)
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
                    'items' => $o->items->map(fn (OrderItem $it): array => [
                        'name' => $it->inventoryItem?->name ?? $it->custom_description,
                        'quantity' => $it->quantity,
                        'unit_type' => $it->unit_type,
                    ])->values()->all(),
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
        $billed = (int) Order::query()->where('is_consignment', false)->sum('total_amount');
        $received = (int) Inflow::query()
            ->whereNotNull('order_id')
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
