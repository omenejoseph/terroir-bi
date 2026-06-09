<?php

declare(strict_types=1);

namespace App\Services\Dashboard;

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
     * @return array<string, mixed>
     */
    public function build(?string $range): array
    {
        $range = is_string($range) && isset(self::RANGE_DAYS[$range]) ? $range : '30D';
        $days = self::RANGE_DAYS[$range];
        $from = Carbon::now()->subDays($days);

        /** @var Collection<int, Order> $orders */
        $orders = Order::query()
            ->where('is_consignment', false)
            ->where('created_at', '>=', $from)
            ->get(['id', 'order_number', 'created_at', 'total_amount', 'status']);

        $points = min($days, 30);
        $step = max(1, intdiv($days, $points));
        $orderCounts = array_fill(0, $points, 0);
        $revenueBuckets = array_fill(0, $points, 0);

        foreach ($orders as $order) {
            $bucket = intdiv((int) ($order->created_at?->diffInDays(Carbon::now()) ?? 0), $step);
            if ($bucket >= 0 && $bucket < $points) {
                $orderCounts[$bucket]++;
                $revenueBuckets[$bucket] += $order->total_amount->getMinorAmount();
            }
        }

        return [
            'range' => $range,
            'currency' => $this->currency(),
            'stats' => [
                'total_orders' => $orders->count(),
                'customers' => Customer::query()->where('is_active', true)->count(),
                'revenue' => (int) $orders->sum(fn (Order $o) => $o->total_amount->getMinorAmount()),
                'low_stock' => $this->analytics->lowStockCount(),
                'outstanding_ar' => $this->outstandingAr(),
                'tasks_overdue' => $this->overdueTasks(),
            ],
            'orders' => $this->series(array_values($orderCounts), $step),
            'revenue' => $this->series(array_values($revenueBuckets), $step),
            'order_status' => $this->orderStatus($orders),
            'top_products' => $this->topProducts($from),
            'stock_watch' => $this->analytics->stockWatch(6),
            'recent_orders' => $this->recentOrders(),
        ];
    }

    /**
     * @param  list<int>  $buckets  newest-first by bucket index
     * @return list<array{label: string, value: int}>
     */
    private function series(array $buckets, int $step): array
    {
        $out = [];
        for ($i = count($buckets) - 1; $i >= 0; $i--) {
            $out[] = ['label' => $this->label($i * $step), 'value' => $buckets[$i]];
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
    private function topProducts(Carbon $from): array
    {
        $rows = OrderItem::query()
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->where('orders.is_consignment', false)
            ->where('orders.created_at', '>=', $from)
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
     * @return list<array{id: string, customer: string, items: int, total: int, status: string, date: string}>
     */
    private function recentOrders(): array
    {
        $rows = Order::query()
            ->with('customer')
            ->withCount('items')
            ->where('is_consignment', false)
            ->orderByDesc('created_at')
            ->limit(5)
            ->get()
            ->map(function (Order $o): array {
                $customer = $o->customer;

                return [
                    'id' => $o->order_number,
                    'customer' => $customer instanceof Customer ? $customer->company_name : '',
                    'items' => (int) $o->getAttribute('items_count'),
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

    private function label(int $daysAgo): string
    {
        return Carbon::now()->subDays($daysAgo)->format('M j');
    }

    private function currency(): string
    {
        return $this->tenant->current()?->settings->default_currency ?? CurrencyRegistry::default()->code;
    }
}
