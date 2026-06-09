<?php

declare(strict_types=1);

namespace App\Actions\Orders;

use App\Enums\ConsignmentReportKind;
use App\Enums\SalesUnit;
use App\Models\InventoryItem;
use App\Models\Order;
use App\Services\Inventory\StockLedger;
use App\Services\Orders\ConsignmentService;
use App\Support\Money\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Close a consignment: auto-return every outstanding bottle (restocking it) and
 * stamp consignment_closed_at.
 */
class CloseConsignmentAction
{
    public function __construct(
        private readonly ConsignmentService $consignment,
        private readonly StockLedger $ledger,
    ) {}

    public function execute(Order $order, string $userId): Order
    {
        if (! $order->is_consignment) {
            throw ValidationException::withMessages(['order' => 'This is not a consignment order.']);
        }

        return DB::transaction(function () use ($order, $userId): Order {
            $tally = $this->consignment->tally($order);
            $currency = $this->consignment->currency($order);

            $remaining = array_filter($tally, fn (array $t) => $t['remaining'] > 0);

            if ($remaining !== []) {
                $report = $order->consignmentReports()->create([
                    'kind' => ConsignmentReportKind::Return,
                    'date' => now(),
                    'note' => 'Auto-return on close',
                    'created_by_id' => $userId,
                ]);

                foreach ($remaining as $id => $t) {
                    $report->items()->create([
                        'order_item_id' => $id,
                        'inventory_item_id' => $t['order_item']->inventory_item_id,
                        'quantity' => $t['remaining'],
                        'unit_price' => Money::fromMinor(0, $currency),
                        'total' => Money::fromMinor(0, $currency),
                    ]);

                    $product = $t['order_item']->inventoryItem;
                    if ($product instanceof InventoryItem) {
                        $this->ledger->restore($product, (string) $t['remaining'], SalesUnit::Bottles->value, reference: $order->order_number.':consignment-close');
                    }
                }
            }

            $order->consignment_closed_at = now();
            $order->save();

            return $order;
        });
    }
}
