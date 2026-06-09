<?php

declare(strict_types=1);

namespace App\Actions\Orders;

use App\Enums\ConsignmentReportKind;
use App\Enums\SalesUnit;
use App\Models\ConsignmentReport;
use App\Models\InventoryItem;
use App\Models\Order;
use App\Services\Inventory\StockLedger;
use App\Services\Orders\ConsignmentService;
use App\Support\Money\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Record a consignment return: zero-revenue lines that put the bottles back into
 * stock (the goods physically come back). Quantities are in bottles and may not
 * exceed what's outstanding.
 */
class RecordConsignmentReturnAction
{
    public function __construct(
        private readonly ConsignmentService $consignment,
        private readonly StockLedger $ledger,
    ) {}

    /**
     * @param  list<array{order_item_id: string, quantity: int|string}>  $items
     */
    public function execute(Order $order, array $items, ?string $note, string $userId): ConsignmentReport
    {
        if (! $order->is_consignment) {
            throw ValidationException::withMessages(['order' => 'This is not a consignment order.']);
        }

        return DB::transaction(function () use ($order, $items, $note, $userId): ConsignmentReport {
            $tally = $this->consignment->tally($order);
            $currency = $this->consignment->currency($order);

            $report = $order->consignmentReports()->create([
                'kind' => ConsignmentReportKind::Return,
                'date' => now(),
                'note' => $note,
                'created_by_id' => $userId,
            ]);

            foreach ($items as $line) {
                $id = $line['order_item_id'];
                $qty = (int) $line['quantity'];
                $t = $tally[$id] ?? throw ValidationException::withMessages(['items' => 'Unknown order line.']);

                if ($qty > $t['remaining']) {
                    throw ValidationException::withMessages(['items' => 'Return exceeds the outstanding quantity.']);
                }
                $tally[$id]['remaining'] -= $qty;

                $report->items()->create([
                    'order_item_id' => $id,
                    'inventory_item_id' => $t['order_item']->inventory_item_id,
                    'quantity' => $qty,
                    'unit_price' => Money::fromMinor(0, $currency),
                    'total' => Money::fromMinor(0, $currency),
                ]);

                $product = $t['order_item']->inventoryItem;
                if ($product instanceof InventoryItem) {
                    $this->ledger->restore($product, (string) $qty, SalesUnit::Bottles->value, reference: $order->order_number.':consignment-return');
                }
            }

            return $report;
        });
    }
}
