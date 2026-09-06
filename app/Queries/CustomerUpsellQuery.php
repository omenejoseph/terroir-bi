<?php

declare(strict_types=1);

namespace App\Queries;

use App\Models\Customer;
use App\Models\InventoryItem;
use App\Models\OrderItem;
use App\Support\Money\CurrencyRegistry;
use App\Support\Money\Money;
use App\Tenancy\Contracts\TenantContext;

/**
 * "Suggest upsell" (Figma 231:9336, next to Price ladder). The ladder itself
 * ranks this customer's own subcategories by revenue per bottle; this finds a
 * real, pricier catalog item in the customer's cheapest-per-bottle bucket
 * that they have not already bought — a step up from what they already buy,
 * not an invented recommendation. No suggestion is returned when the data
 * cannot honestly support one (no purchase history, or nothing pricier left
 * in the catalog for that bucket).
 */
class CustomerUpsellQuery
{
    public function __construct(private readonly TenantContext $tenant) {}

    /**
     * @return array{
     *     bucket: string|null,
     *     current_price_per_bottle: array<string, mixed>|null,
     *     candidates: list<array<string, mixed>>,
     * }
     */
    public function get(Customer $customer, int $limit = 3): array
    {
        $none = ['bucket' => null, 'current_price_per_bottle' => null, 'candidates' => []];
        $currency = $this->currency();

        $lines = OrderItem::query()
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->where('orders.customer_id', $customer->getKey())
            ->whereNotNull('order_items.inventory_item_id')
            ->groupBy('order_items.inventory_item_id')
            ->select('order_items.inventory_item_id as item_id')
            ->selectRaw('SUM(order_items.quantity) as units')
            ->selectRaw('SUM(order_items.total) as revenue')
            ->get();

        if ($lines->isEmpty()) {
            return $none;
        }

        $purchasedIds = array_values($lines->pluck('item_id')->map(fn (mixed $id): string => (string) $id)->all());

        /** @var array<string, InventoryItem> $items */
        $items = InventoryItem::query()
            ->whereIn('id', $purchasedIds)
            ->get()
            ->keyBy(fn (InventoryItem $item): string => (string) $item->getKey())
            ->all();

        // Same bucketing as the page's own price ladder: subcategory, falling
        // back to group. Unlike the ladder, a product with neither is simply
        // excluded here rather than lumped into "Other" — there is no real
        // catalog grouping to search for an upsell within.
        /** @var array<string, array{units: int, revenue_minor: int}> $buckets */
        $buckets = [];
        foreach ($lines as $line) {
            $id = (string) $line->getAttribute('item_id');
            $item = $items[$id] ?? null;
            $key = $item instanceof InventoryItem ? ($item->subcategory ?? $item->group) : null;
            $units = (int) $line->getAttribute('units');

            if ($key === null || $units <= 0) {
                continue;
            }

            $bucket = $buckets[$key] ?? ['units' => 0, 'revenue_minor' => 0];
            $bucket['units'] += $units;
            $bucket['revenue_minor'] += (int) $line->getAttribute('revenue');
            $buckets[$key] = $bucket;
        }

        if ($buckets === []) {
            return $none;
        }

        // The bucket with the most headroom to step up from.
        $bucketKey = null;
        $pricePerBottle = null;
        foreach ($buckets as $key => $bucket) {
            $price = $bucket['units'] > 0 ? intdiv($bucket['revenue_minor'], $bucket['units']) : 0;
            if ($pricePerBottle === null || $price < $pricePerBottle) {
                $pricePerBottle = $price;
                $bucketKey = $key;
            }
        }

        $candidates = InventoryItem::query()
            ->where('is_for_sale', true)
            ->where('is_active', true)
            ->whereNotIn('id', $purchasedIds)
            ->whereNotNull('default_price')
            ->where('default_price', '>', $pricePerBottle)
            ->where(fn ($q) => $q->where('subcategory', $bucketKey)->orWhere('group', $bucketKey))
            ->orderBy('default_price')
            ->limit($limit)
            ->get();

        if ($candidates->isEmpty()) {
            return $none;
        }

        return [
            'bucket' => $bucketKey,
            'current_price_per_bottle' => Money::fromMinor($pricePerBottle, $currency)->jsonSerialize(),
            'candidates' => array_values($candidates->map(fn (InventoryItem $item): array => [
                'inventory_item_id' => (string) $item->getKey(),
                'name' => $item->name,
                'sku' => $item->sku,
                'vintage' => $item->vintage,
                'default_price' => $item->default_price instanceof Money
                    ? $item->default_price->jsonSerialize()
                    : Money::fromMinor(0, $currency)->jsonSerialize(),
            ])->all()),
        ];
    }

    private function currency(): string
    {
        $currency = $this->tenant->current()?->settings()->first()?->default_currency;

        return $currency ?? CurrencyRegistry::default()->code;
    }
}
