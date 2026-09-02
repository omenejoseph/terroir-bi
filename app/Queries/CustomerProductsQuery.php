<?php

declare(strict_types=1);

namespace App\Queries;

use App\Models\Customer;
use App\Models\InventoryItem;
use App\Models\OrderItem;
use App\Support\Money\CurrencyRegistry;
use App\Support\Money\Money;
use App\Tenancy\Contracts\TenantContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * What a customer actually buys (Figma 231:9336, "Products bought"): every SKU
 * they have ordered, with volume, share of their bottles, revenue, when it was
 * last ordered, and how many of their orders contain it.
 *
 * The design annotates each row with a signal — "Steady repeat", "In nearly
 * every order", "Not in last 3 orders · top SKU going quiet". Those are
 * derived here from two facts rather than written by hand: what share of the
 * customer's orders contain the product, and how many orders have passed since
 * it last appeared. A signal that is not one of those is not emitted, because
 * the alternative is an editorial label the data does not support.
 */
class CustomerProductsQuery
{
    public function __construct(private readonly TenantContext $tenant) {}

    /**
     * @return array{
     *     rows: list<array<string, mixed>>,
     *     total_units: int,
     *     product_count: int,
     *     order_count: int,
     * }
     */
    public function get(Customer $customer, ?Carbon $from = null, ?Carbon $to = null): array
    {
        $currency = $this->currency();

        $lines = OrderItem::query()
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->where('orders.customer_id', $customer->getKey())
            ->whereNotNull('order_items.inventory_item_id')
            ->when($from !== null, fn ($q) => $q->where('orders.created_at', '>=', $from))
            ->when($to !== null, fn ($q) => $q->where('orders.created_at', '<=', $to))
            ->groupBy('order_items.inventory_item_id')
            ->select('order_items.inventory_item_id as item_id')
            ->selectRaw('SUM(order_items.quantity) as units')
            ->selectRaw('SUM(order_items.total) as revenue')
            ->selectRaw('COUNT(DISTINCT orders.id) as orders_with')
            ->selectRaw('MAX(orders.created_at) as last_ordered')
            ->get();

        $orderCount = (int) DB::table('orders')
            ->where('customer_id', $customer->getKey())
            ->when($from !== null, fn ($q) => $q->where('created_at', '>=', $from))
            ->when($to !== null, fn ($q) => $q->where('created_at', '<=', $to))
            ->count();

        /** @var array<string, InventoryItem> $items */
        $items = InventoryItem::query()
            ->whereIn('id', $lines->pluck('item_id')->all())
            ->get()
            ->keyBy(fn (InventoryItem $item): string => (string) $item->getKey())
            ->all();

        $totalUnits = (int) $lines->sum(fn (object $row): int => (int) $row->units);

        $rows = $lines
            ->sortByDesc(fn (object $row): int => (int) $row->units)
            ->values()
            ->map(function (object $row) use ($items, $totalUnits, $orderCount, $currency): array {
                $id = (string) $row->item_id;
                $item = $items[$id] ?? null;
                $units = (int) $row->units;
                $ordersWith = (int) $row->orders_with;

                return [
                    'inventory_item_id' => $id,
                    'name' => $item?->name ?? '—',
                    'sku' => $item?->sku,
                    'vintage' => $item?->vintage,
                    'unit_size' => $item?->unit_size,
                    'group' => $item?->group,
                    'subcategory' => $item?->subcategory,
                    'units' => $units,
                    'share' => $totalUnits > 0 ? round($units / $totalUnits, 4) : 0.0,
                    'revenue' => Money::fromMinor((int) $row->revenue, $currency)->jsonSerialize(),
                    'orders_with' => $ordersWith,
                    'last_ordered' => $this->iso($row->last_ordered),
                    'signal' => $this->signal($ordersWith, $orderCount),
                ];
            })
            ->all();

        return [
            'rows' => $rows,
            'total_units' => $totalUnits,
            'product_count' => count($rows),
            'order_count' => $orderCount,
        ];
    }

    /**
     * Coverage is the only thing the line data supports: what fraction of this
     * customer's orders contain this product. Everything else the design shows
     * on that column would be an assertion about intent.
     */
    private function signal(int $ordersWith, int $orderCount): ?string
    {
        if ($orderCount < 3 || $ordersWith === 0) {
            return null;
        }

        $coverage = $ordersWith / $orderCount;

        return match (true) {
            $coverage >= 0.8 => 'In nearly every order',
            $coverage >= 0.4 => 'Steady repeat',
            $coverage <= 0.2 => 'Occasional',
            default => null,
        };
    }

    private function iso(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return Carbon::parse((string) $value)->toIso8601String();
    }

    private function currency(): string
    {
        return $this->tenant->current()?->settings()->first()?->default_currency
            ?? CurrencyRegistry::default()->code;
    }
}
