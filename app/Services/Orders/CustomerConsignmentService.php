<?php

declare(strict_types=1);

namespace App\Services\Orders;

use App\Actions\Orders\RecordConsignmentReturnAction;
use App\Actions\Orders\RecordConsignmentSaleAction;
use App\Models\Customer;
use App\Models\InventoryItem;
use App\Models\Order;
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
     * @return array<string, mixed>
     */
    public function summary(Customer $customer): array
    {
        $lines = $this->openLines($customer, includeEmpty: true);

        $byProduct = [];
        foreach ($lines as $line) {
            $id = $line['product_id'];
            $byProduct[$id] ??= ['inventory_item_id' => $id, 'name' => $line['name'], 'placed' => 0, 'sold' => 0, 'returned' => 0, 'remaining' => 0];
            $byProduct[$id]['placed'] += $line['placed'];
            $byProduct[$id]['sold'] += $line['sold'];
            $byProduct[$id]['returned'] += $line['returned'];
            $byProduct[$id]['remaining'] += $line['remaining'];
        }

        return [
            'products' => array_values($byProduct),
            'placements' => $customer->orders()
                ->where('is_consignment', true)
                ->orderBy('created_at')
                ->get(['id', 'order_number', 'created_at', 'consignment_closed_at'])
                ->map(fn (Order $o) => [
                    'order_id' => $o->getKey(),
                    'order_number' => $o->order_number,
                    'placed_at' => $o->created_at?->toIso8601String(),
                    'closed_at' => $o->consignment_closed_at?->toIso8601String(),
                ])->all(),
        ];
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
     * outstanding tallies.
     *
     * @return list<array{order: Order, order_item_id: string, product_id: string, name: string, placed: int, sold: int, returned: int, remaining: int}>
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
                ];
            }
        }

        return $lines;
    }
}
