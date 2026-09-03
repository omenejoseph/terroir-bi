<?php

declare(strict_types=1);

namespace App\Services\Orders;

use App\Actions\Orders\RecordConsignmentReturnAction;
use App\Actions\Orders\RecordConsignmentSaleAction;
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
     * @param  list<array{order: Order, remaining: int}>  $lines  Same lines summary() already built, so this doesn't re-query.
     * @return list<array<string, mixed>>
     */
    private function placements(Customer $customer, array $lines): array
    {
        $remainingByOrder = [];
        foreach ($lines as $line) {
            $orderId = $line['order']->getKey();
            $remainingByOrder[$orderId] = ($remainingByOrder[$orderId] ?? 0) + $line['remaining'];
        }

        return $customer->orders()
            ->where('is_consignment', true)
            ->orderByDesc('created_at')
            ->get(['id', 'order_number', 'created_at', 'consignment_closed_at'])
            ->map(fn (Order $o): array => [
                'order_id' => $o->getKey(),
                'order_number' => $o->order_number,
                'placed_at' => $o->created_at?->toIso8601String(),
                'closed_at' => $o->consignment_closed_at?->toIso8601String(),
                'remaining' => $remainingByOrder[$o->getKey()] ?? 0,
            ])
            ->filter(fn (array $p): bool => $p['remaining'] > 0 || $p['closed_at'] === null)
            ->values()
            ->all();
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
        $orders = $customer->orders()
            ->where('is_consignment', true)
            ->orderBy('created_at')
            ->orderBy('id')
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
