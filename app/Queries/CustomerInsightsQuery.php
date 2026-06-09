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
use Illuminate\Support\Facades\DB;

/**
 * All-time commercial snapshot for one customer: spend, order cadence, realized
 * consignment revenue, and best-selling products. Consignment placements are
 * excluded from spend (revenue is recognized via sell-through instead).
 */
class CustomerInsightsQuery
{
    public function __construct(private readonly TenantContext $tenant) {}

    /**
     * @return array<string, mixed>
     */
    public function get(Customer $customer): array
    {
        $currency = $this->currency();

        $orders = Order::query()
            ->where('customer_id', $customer->getKey())
            ->where('is_consignment', false);

        $totalSpend = (int) (clone $orders)->sum('total_amount');
        $orderCount = (clone $orders)->count();
        $lastOrderAt = (clone $orders)->max('created_at');

        return [
            'total_spend' => Money::fromMinor($totalSpend, $currency)->jsonSerialize(),
            'order_count' => $orderCount,
            'avg_order_value' => Money::fromMinor($orderCount > 0 ? intdiv($totalSpend, $orderCount) : 0, $currency)->jsonSerialize(),
            'last_order_date' => is_string($lastOrderAt) ? $lastOrderAt : null,
            'consignment_revenue' => Money::fromMinor($this->consignmentRevenue($customer), $currency)->jsonSerialize(),
            'top_products' => $this->topProducts($customer, $currency),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function topProducts(Customer $customer, string $currency): array
    {
        $rows = OrderItem::query()
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->where('orders.customer_id', $customer->getKey())
            ->where('orders.is_consignment', false)
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

    private function consignmentRevenue(Customer $customer): int
    {
        return (int) ConsignmentReportItem::query()
            ->join('consignment_reports', 'consignment_reports.id', '=', 'consignment_report_items.report_id')
            ->join('orders', 'orders.id', '=', 'consignment_reports.order_id')
            ->where('orders.customer_id', $customer->getKey())
            ->where('consignment_reports.kind', ConsignmentReportKind::Sale->value)
            ->sum('consignment_report_items.total');
    }

    private function currency(): string
    {
        $currency = $this->tenant->current()?->settings()->first()?->default_currency;

        return $currency ?? CurrencyRegistry::default()->code;
    }
}
