<?php

declare(strict_types=1);

namespace App\Queries;

use App\Enums\ConsignmentReportKind;
use App\Models\ConsignmentReportItem;
use App\Models\Customer;
use App\Models\InventoryItem;
use App\Models\Order;
use App\Models\OrderItem;
use App\Support\Money\CurrencyRegistry;
use App\Support\Money\Money;
use App\Tenancy\Contracts\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Order profitability for a period: revenue/COGS/margin, top customers/products,
 * and low-margin orders. COGS comes from the per-line snapshot on the *current*
 * order lines (not stock movements), so it is inherently immune to the
 * ORDER_DEDUCT double-count and to reconciliation adjustments. Consignment
 * orders are excluded from the core P&L; their realized sell-through is reported
 * separately as consignment_revenue.
 */
class OrderAnalyticsQuery
{
    private const LOW_MARGIN_THRESHOLD = 0.15;

    public function __construct(private readonly TenantContext $tenant) {}

    /**
     * @return array<string, mixed>
     */
    public function get(Carbon $from, Carbon $to): array
    {
        $currency = $this->currency();

        $revenue = (int) $this->lines($from, $to)->sum('order_items.total');
        $cogs = (int) $this->lines($from, $to)
            ->whereNotNull('order_items.cost_per_unit')
            ->sum(DB::raw('order_items.cost_per_unit * order_items.quantity'));
        $unknownCost = $this->lines($from, $to)->whereNull('order_items.cost_per_unit')->count();

        $orderCount = Order::query()
            ->where('is_consignment', false)
            ->whereBetween('created_at', [$from, $to])
            ->count();

        $profit = $revenue - $cogs;
        $avg = $orderCount > 0 ? intdiv($revenue, $orderCount) : 0;

        return [
            'period' => ['from' => $from->toIso8601String(), 'to' => $to->toIso8601String()],
            'revenue' => Money::fromMinor($revenue, $currency)->jsonSerialize(),
            'cogs' => Money::fromMinor($cogs, $currency)->jsonSerialize(),
            'gross_profit' => Money::fromMinor($profit, $currency)->jsonSerialize(),
            'margin_percent' => $revenue > 0 ? number_format($profit / $revenue * 100, 2, '.', '') : '0.00',
            'order_count' => $orderCount,
            'avg_order_value' => Money::fromMinor($avg, $currency)->jsonSerialize(),
            'items_with_unknown_cost' => $unknownCost,
            'consignment_revenue' => Money::fromMinor($this->consignmentRevenue($from, $to), $currency)->jsonSerialize(),
            'top_customers' => $this->topCustomers($from, $to, $currency),
            'top_products' => $this->topProducts($from, $to, $currency),
            'low_margin_orders' => $this->lowMarginOrders($from, $to, $currency),
        ];
    }

    /**
     * @return Builder<OrderItem>
     */
    private function lines(Carbon $from, Carbon $to): Builder
    {
        return OrderItem::query()
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->where('orders.is_consignment', false)
            ->whereBetween('orders.created_at', [$from, $to]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function topCustomers(Carbon $from, Carbon $to, string $currency): array
    {
        $rows = Order::query()
            ->where('is_consignment', false)
            ->whereBetween('created_at', [$from, $to])
            ->groupBy('customer_id')
            ->orderByDesc('rev')
            ->limit(5)
            ->get([DB::raw('customer_id'), DB::raw('SUM(total_amount) as rev')]);

        $names = Customer::query()->whereIn('id', $rows->pluck('customer_id'))->pluck('company_name', 'id');

        return array_values($rows->map(fn (Order $row) => [
            'customer_id' => $row->customer_id,
            'company_name' => $names[$row->customer_id] ?? null,
            'revenue' => Money::fromMinor((int) $row->getAttribute('rev'), $currency)->jsonSerialize(),
        ])->all());
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function topProducts(Carbon $from, Carbon $to, string $currency): array
    {
        $rows = $this->lines($from, $to)
            ->whereNotNull('order_items.inventory_item_id')
            ->groupBy('order_items.inventory_item_id')
            ->orderByDesc('rev')
            ->limit(5)
            ->get([
                DB::raw('order_items.inventory_item_id as item_id'),
                DB::raw('SUM(order_items.total) as rev'),
                DB::raw('SUM(order_items.quantity) as qty'),
            ]);

        $names = InventoryItem::query()->whereIn('id', $rows->pluck('item_id'))->pluck('name', 'id');

        return array_values($rows->map(fn (OrderItem $row) => [
            'inventory_item_id' => $row->getAttribute('item_id'),
            'name' => $names[$row->getAttribute('item_id')] ?? null,
            'quantity' => (int) $row->getAttribute('qty'),
            'revenue' => Money::fromMinor((int) $row->getAttribute('rev'), $currency)->jsonSerialize(),
        ])->all());
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function lowMarginOrders(Carbon $from, Carbon $to, string $currency): array
    {
        $rows = $this->lines($from, $to)
            ->groupBy('orders.id', 'orders.order_number')
            ->get([
                DB::raw('orders.id as oid'),
                DB::raw('orders.order_number as onum'),
                DB::raw('SUM(order_items.total) as rev'),
                DB::raw('SUM(CASE WHEN order_items.cost_per_unit IS NOT NULL THEN order_items.cost_per_unit * order_items.quantity ELSE 0 END) as cogs'),
            ]);

        $low = $rows
            ->filter(function (OrderItem $row): bool {
                $rev = (int) $row->getAttribute('rev');

                return $rev > 0 && ($rev - (int) $row->getAttribute('cogs')) / $rev < self::LOW_MARGIN_THRESHOLD;
            })
            ->sortBy(fn (OrderItem $row) => ((int) $row->getAttribute('rev') - (int) $row->getAttribute('cogs')) / max(1, (int) $row->getAttribute('rev')))
            ->take(10)
            ->map(function (OrderItem $row) use ($currency): array {
                $rev = (int) $row->getAttribute('rev');
                $cogs = (int) $row->getAttribute('cogs');

                return [
                    'order_id' => $row->getAttribute('oid'),
                    'order_number' => $row->getAttribute('onum'),
                    'revenue' => Money::fromMinor($rev, $currency)->jsonSerialize(),
                    'cogs' => Money::fromMinor($cogs, $currency)->jsonSerialize(),
                    'margin_percent' => $rev > 0 ? number_format(($rev - $cogs) / $rev * 100, 2, '.', '') : '0.00',
                ];
            })
            ->all();

        return array_values($low);
    }

    private function consignmentRevenue(Carbon $from, Carbon $to): int
    {
        return (int) ConsignmentReportItem::query()
            ->join('consignment_reports', 'consignment_reports.id', '=', 'consignment_report_items.report_id')
            ->where('consignment_reports.kind', ConsignmentReportKind::Sale->value)
            ->whereBetween('consignment_reports.date', [$from, $to])
            ->sum('consignment_report_items.total');
    }

    private function currency(): string
    {
        $currency = $this->tenant->current()?->settings()->first()?->default_currency;

        return $currency ?? CurrencyRegistry::default()->code;
    }
}
