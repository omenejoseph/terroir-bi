<?php

declare(strict_types=1);

namespace App\Services\Orders;

use App\Actions\Orders\RecordConsignmentReturnAction;
use App\Actions\Orders\RecordConsignmentSaleAction;
use App\Models\ConsignmentReport;
use App\Models\ConsignmentReportItem;
use App\Models\Customer;
use App\Models\InventoryItem;
use App\Models\Order;
use App\Support\Money\CurrencyRegistry;
use App\Support\Money\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Customer-level view over their consignment placements, and FIFO allocation of
 * sales/returns across those placements (oldest first). Each chunk is recorded
 * against the underlying order via the order-level actions, so per-placement
 * price/cost and stock effects stay correct.
 */
class CustomerConsignmentService
{
    public function __construct(
        private readonly ConsignmentService $consignment,
        private readonly RecordConsignmentSaleAction $sales,
        private readonly RecordConsignmentReturnAction $returns,
    ) {}

    /**
     * Money fields are always computed here — the customer's own financial
     * visibility is the caller's decision to make, not this service's; see
     * Web\CustomerController::show(), which withholds them the same way it
     * already withholds order totals for a viewer without financials.view.
     *
     * @return array<string, mixed>
     */
    public function summary(Customer $customer): array
    {
        $lines = $this->openLines($customer, includeEmpty: true);
        $currency = CurrencyRegistry::default()->code;

        $byProduct = [];
        foreach ($lines as $line) {
            $id = $line['product_id'];
            $byProduct[$id] ??= [
                'inventory_item_id' => $id, 'name' => $line['name'],
                'placed' => 0, 'sold' => 0, 'returned' => 0, 'remaining' => 0,
                'revenue_minor' => 0, 'cogs_minor' => 0,
            ];
            $byProduct[$id]['placed'] += $line['placed'];
            $byProduct[$id]['sold'] += $line['sold'];
            $byProduct[$id]['returned'] += $line['returned'];
            $byProduct[$id]['remaining'] += $line['remaining'];
            $byProduct[$id]['revenue_minor'] += $line['revenue_minor'];
            $byProduct[$id]['cogs_minor'] += $line['cogs_minor'];
            $currency = $line['currency'];
        }

        $products = array_map(
            fn (array $p): array => $this->presentProduct($p, $currency),
            array_values($byProduct),
        );

        // Most remaining first, then name — what still needs selling matters
        // more than what has already cleared.
        usort($products, fn (array $a, array $b): int => $b['remaining'] <=> $a['remaining'] ?: strcmp($a['name'], $b['name']));

        $totalRevenueMinor = array_sum(array_column($byProduct, 'revenue_minor'));
        $totalCogsMinor = array_sum(array_column($byProduct, 'cogs_minor'));
        $totalProfitMinor = $totalRevenueMinor - $totalCogsMinor;

        return [
            'products' => $products,
            // Only placements still open — nothing outstanding and formally
            // closed drops off, same as a settled invoice leaving a ledger.
            'placements' => $this->placements($customer, $lines),
            'total_remaining' => array_sum(array_column($products, 'remaining')),
            'total_sold_revenue' => Money::fromMinor($totalRevenueMinor, $currency)->jsonSerialize(),
            'total_sold_gross_profit' => Money::fromMinor($totalProfitMinor, $currency)->jsonSerialize(),
            'total_sold_margin_percent' => $totalRevenueMinor > 0
                ? number_format($totalProfitMinor / $totalRevenueMinor * 100, 1, '.', '')
                : null,
        ];
    }

    /**
     * @param  array{inventory_item_id: string, name: string, placed: int, sold: int, returned: int, remaining: int, revenue_minor: int, cogs_minor: int}  $p
     * @return array<string, mixed>
     */
    private function presentProduct(array $p, string $currency): array
    {
        $profit = $p['revenue_minor'] - $p['cogs_minor'];

        return [
            'inventory_item_id' => $p['inventory_item_id'],
            'name' => $p['name'],
            'placed' => $p['placed'],
            'sold' => $p['sold'],
            'returned' => $p['returned'],
            'remaining' => $p['remaining'],
            'sold_revenue' => Money::fromMinor($p['revenue_minor'], $currency)->jsonSerialize(),
            'margin_percent' => $p['revenue_minor'] > 0
                ? number_format($profit / $p['revenue_minor'] * 100, 1, '.', '')
                : null,
        ];
    }

    /**
     * Every placement the customer still has outstanding stock against, plus
     * any not yet formally closed — a closed placement with nothing left is
     * settled and has nothing more to say.
     *
     * `$lines` must come from `openLines($customer, includeEmpty: true)` —
     * summary()'s only caller does — so every consignment order (not just
     * ones with something still outstanding) is represented by at least one
     * line and this can read the order set straight off it instead of
     * re-querying the same orders a second time.
     *
     * @param  list<array{order: Order, remaining: int}>  $lines
     * @return list<array<string, mixed>>
     */
    private function placements(Customer $customer, array $lines): array
    {
        $remainingByOrder = [];
        /** @var array<string, Order> $ordersById */
        $ordersById = [];
        foreach ($lines as $line) {
            $orderId = $line['order']->getKey();
            $remainingByOrder[$orderId] = ($remainingByOrder[$orderId] ?? 0) + $line['remaining'];
            $ordersById[$orderId] ??= $line['order'];
        }

        return array_values(collect($ordersById)
            ->sortByDesc(fn (Order $o) => $o->created_at)
            ->map(fn (Order $o): array => [
                'order_id' => $o->getKey(),
                'order_number' => $o->order_number,
                'placed_at' => $o->created_at?->toIso8601String(),
                'closed_at' => $o->consignment_closed_at?->toIso8601String(),
                'remaining' => $remainingByOrder[$o->getKey()] ?? 0,
            ])
            ->filter(fn (array $p): bool => $p['remaining'] > 0 || $p['closed_at'] === null)
            ->values()
            ->all());
    }

    /**
     * Every sale/return recorded against any of this customer's consignment
     * placements, newest first — the individual `ConsignmentReport` rows
     * `sale()`/`return()` write, which `summary()`'s per-product rollup
     * necessarily flattens away. Genuinely absent everywhere else: a report
     * is not an `Order`, so it never appears in the Order History tab
     * (`Web\CustomerController::orderHistory()` reads `orders` only) — unlike
     * the placement itself, which does.
     *
     * @return list<array<string, mixed>>
     */
    public function history(Customer $customer, bool $includeFinancials, int $limit = 20): array
    {
        $reports = ConsignmentReport::query()
            ->whereHas('order', fn ($q) => $q->where('customer_id', $customer->getKey())->where('is_consignment', true))
            ->with(['order', 'createdBy', 'items.inventoryItem'])
            ->orderByDesc('date')
            ->orderByDesc('id')
            ->limit($limit)
            ->get();

        return array_values($reports->map(fn (ConsignmentReport $report): array => [
            'id' => $report->getKey(),
            'kind' => $report->kind->value,
            'date' => $report->date->toIso8601String(),
            'order_number' => $report->order?->order_number,
            'note' => $report->note,
            'created_by_name' => $report->createdBy?->fullName(),
            'items' => array_values($report->items->map(function (ConsignmentReportItem $item) use ($includeFinancials): array {
                $product = $item->inventoryItem;

                return [
                    // A custom (non-catalog) order line carries no
                    // inventory_item_id, so this genuinely can be null —
                    // unlike an item lookup, there is no delete-time guard
                    // that rules it out here.
                    'name' => $product instanceof InventoryItem ? $product->name : '—',
                    'quantity' => $item->quantity,
                    'total' => $includeFinancials ? $item->total->jsonSerialize() : null,
                ];
            })->all()),
        ])->all());
    }

    /**
     * @param  list<array{inventory_item_id: string, quantity: int|string, unit_price?: int|string|null}>  $items
     */
    public function sale(Customer $customer, array $items, ?string $note, string $userId): void
    {
        $this->allocate($customer, $items, 'sale', $note, $userId);
    }

    /**
     * @param  list<array{inventory_item_id: string, quantity: int|string}>  $items
     */
    public function return(Customer $customer, array $items, ?string $note, string $userId): void
    {
        $this->allocate($customer, $items, 'return', $note, $userId);
    }

    /**
     * @param  list<array<string, mixed>>  $items
     */
    private function allocate(Customer $customer, array $items, string $kind, ?string $note, string $userId): void
    {
        DB::transaction(function () use ($customer, $items, $kind, $note, $userId): void {
            $lines = $this->openLines($customer, includeEmpty: false);

            /** @var array<string, array{order: Order, items: list<array<string, mixed>>}> $perOrder */
            $perOrder = [];

            foreach ($items as $request) {
                $productId = (string) $request['inventory_item_id'];
                $needed = (int) $request['quantity'];
                $override = isset($request['unit_price']) ? (int) $request['unit_price'] : null;

                foreach ($lines as &$line) {
                    if ($needed <= 0) {
                        break;
                    }
                    if ($line['product_id'] !== $productId || $line['remaining'] <= 0) {
                        continue;
                    }

                    $take = min($needed, $line['remaining']);
                    $orderId = $line['order']->getKey();
                    $perOrder[$orderId] ??= ['order' => $line['order'], 'items' => []];

                    $entry = ['order_item_id' => $line['order_item_id'], 'quantity' => $take];
                    if ($kind === 'sale' && $override !== null) {
                        $entry['unit_price'] = $override;
                    }
                    $perOrder[$orderId]['items'][] = $entry;

                    $line['remaining'] -= $take;
                    $needed -= $take;
                }
                unset($line);

                if ($needed > 0) {
                    throw ValidationException::withMessages([
                        'items' => "Not enough outstanding consignment stock to {$kind} the requested quantity.",
                    ]);
                }
            }

            foreach ($perOrder as $group) {
                if ($kind === 'sale') {
                    /** @var list<array{order_item_id: string, quantity: int|string, unit_price?: int|string|null}> $saleItems */
                    $saleItems = $group['items'];
                    $this->sales->execute($group['order'], $saleItems, $note, $userId);
                } else {
                    /** @var list<array{order_item_id: string, quantity: int|string}> $returnItems */
                    $returnItems = $group['items'];
                    $this->returns->execute($group['order'], $returnItems, $note, $userId);
                }
            }
        });
    }

    /**
     * Flat, oldest-first list of the customer's consignment lines with their
     * outstanding tallies. Unknown cost (no cost_per_unit on the placement
     * line) counts as zero rather than being excluded — the same choice the
     * per-order consignment summary makes.
     *
     * @return list<array{order: Order, order_item_id: string, product_id: string, name: string, placed: int, sold: int, returned: int, remaining: int, revenue_minor: int, cogs_minor: int, currency: string}>
     */
    private function openLines(Customer $customer, bool $includeEmpty): array
    {
        // Eager-loaded so currency()'s `$order->items` and tally()'s own
        // loadMissing() below find everything already in memory — without
        // this, both fire their own extra queries per order.
        $orders = $customer->orders()
            ->where('is_consignment', true)
            ->orderBy('created_at')
            ->orderBy('id')
            ->with(['items.inventoryItem', 'consignmentReports.items'])
            ->get();

        $lines = [];
        foreach ($orders as $order) {
            $currency = $this->consignment->currency($order);

            foreach ($this->consignment->tally($order) as $orderItemId => $t) {
                if (! $includeEmpty && $t['remaining'] <= 0) {
                    continue;
                }

                $product = $t['order_item']->inventoryItem;
                $lines[] = [
                    'order' => $order,
                    'order_item_id' => (string) $orderItemId,
                    'product_id' => (string) $t['order_item']->inventory_item_id,
                    'name' => $product instanceof InventoryItem ? $product->name : (string) $t['order_item']->custom_description,
                    'placed' => $t['placed'],
                    'sold' => $t['sold'],
                    'returned' => $t['returned'],
                    'remaining' => $t['remaining'],
                    'revenue_minor' => $t['revenue_minor'],
                    'cogs_minor' => $t['per_bottle_cost'] !== null ? $t['per_bottle_cost'] * $t['sold'] : 0,
                    'currency' => $currency,
                ];
            }
        }

        return $lines;
    }
}
