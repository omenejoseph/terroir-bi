<?php

declare(strict_types=1);

namespace App\Queries;

use App\Enums\CustomerType;
use App\Enums\StockMovementType;
use App\Models\InventoryItem;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\StockMovement;
use App\Support\Money\Money;
use Illuminate\Support\Carbon;

/**
 * Where one item's bottles went in a window, by CUSTOMER channel (the product page's "Exit by channel"):
 *
 * - `internal`    — customers flagged exclude-from-stats (Internal / POS)
 * - `distributor` — wholesale and agency customers (Distributor / Importer)
 * - `retailer`    — retail and ship-shop customers (Retailer / Shop)
 * - `other`       — any other customer
 * - `non_order`   — production and manual exits, which have no customer
 *
 * Exits are the same genuine, self-healed exits the Inventory Spend page counts (no count corrections;
 * an order deduct is scaled to what the live order line still says, so a deleted order contributes
 * nothing). What that healing removes is reported as `excluded_bottles` — "from edited / deleted
 * orders" — so the rows plus the exclusion reconcile to the raw order deducts. Revenue is the live,
 * non-consignment order lines for the channel.
 */
class ItemExitChannelsQuery
{
    public const ORDER = ['internal', 'distributor', 'retailer', 'other', 'non_order'];

    public function __construct(private readonly InventorySpendQuery $spend) {}

    /**
     * @return array{
     *     rows: list<array{key: string, bottles: int, revenue: ?array<string, mixed>, share: float}>,
     *     total_bottles: int,
     *     movements_count: int,
     *     excluded_bottles: int,
     *     spark: list<int>,
     *     spark_internal: list<int>,
     *     spark_start: string,
     *     spark_step_days: float,
     * }
     */
    public function get(InventoryItem $item, Carbon $from, Carbon $to, int $days, string $currency): array
    {
        $caseUnit = in_array(strtolower((string) $item->unit), ['case', 'cases'], true);
        $bpc = max(1, (int) $item->bottles_per_case);
        $toBottles = function (float $qty, ?string $unit) use ($caseUnit, $bpc): float {
            $isCase = $unit !== null && $unit !== ''
                ? in_array(strtolower($unit), ['case', 'cases'], true)
                : $caseUnit;

            return abs($qty) * ($isCase ? $bpc : 1);
        };

        $movements = StockMovement::query()
            ->where('inventory_item_id', $item->getKey())
            ->where('quantity', '<', 0)
            ->where('type', '!=', StockMovementType::Adjustment->value)
            ->where('is_reconciliation', false)
            ->whereBetween('created_at', [$from, $to])
            ->get(['type', 'quantity', 'unit', 'reference', 'created_at']);

        /** @var list<string> $orderRefs */
        $orderRefs = $movements
            ->filter(fn (StockMovement $m): bool => $m->type === StockMovementType::OrderDeduct && $m->reference !== null)
            ->map(fn (StockMovement $m): string => (string) $m->reference)
            ->unique()
            ->values()
            ->all();

        $factors = $orderRefs === [] ? [] : $this->spend->deductFactors($orderRefs);
        $buckets = $this->bucketsByOrderNumber($orderRefs);

        $bottles = array_fill_keys(self::ORDER, 0.0);
        $excluded = 0.0;
        $sparkSlots = min(max(1, $days), 90);
        $spark = array_fill(0, $sparkSlots, 0.0);
        $sparkInternal = array_fill(0, $sparkSlots, 0.0);
        $count = 0;
        $slot = function (?Carbon $at) use ($from, $days, $sparkSlots): int {
            $offset = $at !== null ? (int) $from->diffInDays($at) : 0;

            return (int) min($sparkSlots - 1, max(0, floor($offset / max(1, $days) * $sparkSlots)));
        };

        foreach ($movements as $m) {
            $raw = $toBottles((float) $m->quantity, $m->unit);

            if ($m->type !== StockMovementType::OrderDeduct) {
                $bottles['non_order'] += $raw;
                $spark[$slot($m->created_at)] += $raw;
                $count++;

                continue;
            }

            // Same rule as the Inventory Spend page: an order deduct is scaled to what the live order
            // still says; one with no order reference cannot be checked, so it counts in full.
            $factor = $m->reference !== null ? ($factors[$m->reference][$item->getKey()] ?? 0.0) : 1.0;
            $healed = $raw * $factor;
            $excluded += $raw - $healed;

            if ($healed <= 0.0) {
                continue;
            }

            $bucket = $m->reference !== null ? ($buckets[$m->reference] ?? 'other') : 'other';
            $bottles[$bucket] += $healed;
            $spark[$slot($m->created_at)] += $healed;
            $count++;

            if ($bucket === 'internal') {
                $sparkInternal[$slot($m->created_at)] += $healed;
            }
        }

        $revenue = $this->revenueByBucket($item, $from, $to);

        $rows = [];
        $total = 0;
        foreach (self::ORDER as $key) {
            $count_ = (int) round($bottles[$key]);

            if ($count_ <= 0) {
                continue;
            }

            $total += $count_;
            $rows[] = [
                'key' => $key,
                'bottles' => $count_,
                'revenue' => $key === 'non_order' ? null : Money::fromMinor($revenue[$key] ?? 0, $currency)->jsonSerialize(),
                'share' => 0.0,
            ];
        }

        foreach ($rows as &$row) {
            $row['share'] = $total > 0 ? round($row['bottles'] / $total, 4) : 0.0;
        }
        unset($row);

        return [
            'rows' => $rows,
            'total_bottles' => $total,
            'movements_count' => $count,
            'excluded_bottles' => (int) round($excluded),
            'spark' => array_values(array_map(fn (float $v): int => (int) round($v), $spark)),
            'spark_internal' => array_values(array_map(fn (float $v): int => (int) round($v), $sparkInternal)),
            // Where the histogram starts and how many days each bar spans, so a bar can be named by date.
            'spark_start' => $from->toDateString(),
            'spark_step_days' => round(max(1, $days) / $sparkSlots, 2),
        ];
    }

    /**
     * Channel bucket per order number, for the orders these deducts reference.
     *
     * @param  list<string>  $orderNumbers
     * @return array<string, string>
     */
    private function bucketsByOrderNumber(array $orderNumbers): array
    {
        if ($orderNumbers === []) {
            return [];
        }

        $out = [];

        Order::query()
            ->join('customers', 'customers.id', '=', 'orders.customer_id')
            ->whereIn('orders.order_number', $orderNumbers)
            ->get(['orders.order_number as ref', 'customers.exclude_from_stats as internal', 'customers.customer_type as ctype'])
            ->each(function (Order $row) use (&$out): void {
                $out[(string) $row->getAttribute('ref')] = $this->bucket(
                    (bool) $row->getAttribute('internal'),
                    (string) $row->getAttribute('ctype'),
                );
            });

        return $out;
    }

    /**
     * Live, non-consignment order revenue for the item in the window, per channel bucket.
     *
     * @return array<string, int>
     */
    private function revenueByBucket(InventoryItem $item, Carbon $from, Carbon $to): array
    {
        $out = [];

        OrderItem::query()
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->join('customers', 'customers.id', '=', 'orders.customer_id')
            ->where('order_items.inventory_item_id', $item->getKey())
            ->where('orders.is_consignment', false)
            ->whereBetween('orders.created_at', [$from, $to])
            ->get(['order_items.total as line_total', 'customers.exclude_from_stats as internal', 'customers.customer_type as ctype'])
            ->each(function (OrderItem $row) use (&$out): void {
                $bucket = $this->bucket((bool) $row->getAttribute('internal'), (string) $row->getAttribute('ctype'));
                $out[$bucket] = ($out[$bucket] ?? 0) + (int) $row->getAttribute('line_total');
            });

        return $out;
    }

    private function bucket(bool $internal, string $customerType): string
    {
        if ($internal) {
            return 'internal';
        }

        return match (CustomerType::tryFrom($customerType)) {
            CustomerType::Wholesale, CustomerType::Agency => 'distributor',
            CustomerType::Retail, CustomerType::Shipshop => 'retailer',
            default => 'other',
        };
    }
}
