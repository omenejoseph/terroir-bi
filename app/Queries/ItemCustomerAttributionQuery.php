<?php

declare(strict_types=1);

namespace App\Queries;

use App\Models\Customer;
use App\Models\InventoryItem;
use App\Models\OrderItem;
use Illuminate\Support\Carbon;

/**
 * Who buys this item (Item — View drawer's "Who's buying it", Figma
 * 378:1592): every customer with an order line for it, ranked by their share
 * of its total ordered volume, and when they last ordered it.
 *
 * The inverse of App\Queries\CustomerProductsQuery, which rolls up one
 * customer's lines by product; this rolls up one product's lines by customer.
 */
class ItemCustomerAttributionQuery
{
    /**
     * @return list<array<string, mixed>>
     */
    public function get(InventoryItem $item, int $limit = 20): array
    {
        $lines = OrderItem::query()
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->where('order_items.inventory_item_id', $item->getKey())
            ->whereNotNull('orders.customer_id')
            ->groupBy('orders.customer_id')
            ->select('orders.customer_id as customer_id')
            ->selectRaw('SUM(order_items.quantity) as units')
            ->selectRaw('MAX(orders.created_at) as last_ordered')
            ->get();

        $totalUnits = (int) $lines->sum(fn (OrderItem $row): int => (int) $row->getAttribute('units'));

        /** @var array<string, Customer> $customers */
        $customers = Customer::query()
            ->whereIn('id', $lines->pluck('customer_id')->all())
            ->get()
            ->keyBy(fn (Customer $customer): string => (string) $customer->getKey())
            ->all();

        return array_values($lines
            ->sortByDesc(fn (OrderItem $row): int => (int) $row->getAttribute('units'))
            ->take($limit)
            ->values()
            ->map(function (OrderItem $row) use ($customers, $totalUnits): array {
                $id = (string) $row->getAttribute('customer_id');
                $customer = $customers[$id] ?? null;
                $units = (int) $row->getAttribute('units');

                return [
                    'customer_id' => $id,
                    'company_name' => $customer->company_name ?? '—',
                    'units' => $units,
                    'share' => $totalUnits > 0 ? round($units / $totalUnits, 4) : 0.0,
                    'last_ordered' => $this->iso($row->getAttribute('last_ordered')),
                ];
            })
            ->all());
    }

    private function iso(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return Carbon::parse((string) $value)->toIso8601String();
    }
}
