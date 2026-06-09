<?php

declare(strict_types=1);

namespace App\Actions\Orders;

use App\Enums\ConsignmentReportKind;
use App\Models\ConsignmentReport;
use App\Models\Order;
use App\Services\Orders\ConsignmentService;
use App\Support\Money\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Record a consignment sell-through: recognizes revenue (and COGS via the
 * placement's per-bottle cost), with no stock effect — the goods already left at
 * placement. Quantities are in bottles and may not exceed what's outstanding.
 */
class RecordConsignmentSaleAction
{
    public function __construct(private readonly ConsignmentService $consignment) {}

    /**
     * @param  list<array{order_item_id: string, quantity: int|string, unit_price?: int|string|null}>  $items
     */
    public function execute(Order $order, array $items, ?string $note, string $userId): ConsignmentReport
    {
        $this->assertConsignment($order);

        return DB::transaction(function () use ($order, $items, $note, $userId): ConsignmentReport {
            $tally = $this->consignment->tally($order);
            $currency = $this->consignment->currency($order);

            $report = $order->consignmentReports()->create([
                'kind' => ConsignmentReportKind::Sale,
                'date' => now(),
                'note' => $note,
                'created_by_id' => $userId,
            ]);

            foreach ($items as $line) {
                $id = $line['order_item_id'];
                $qty = (int) $line['quantity'];
                $t = $tally[$id] ?? throw ValidationException::withMessages(['items' => 'Unknown order line.']);

                if ($qty > $t['remaining']) {
                    throw ValidationException::withMessages(['items' => 'Sale exceeds the outstanding quantity.']);
                }
                $tally[$id]['remaining'] -= $qty;

                $price = isset($line['unit_price']) ? (int) $line['unit_price'] : $t['per_bottle_price'];

                $report->items()->create([
                    'order_item_id' => $id,
                    'inventory_item_id' => $t['order_item']->inventory_item_id,
                    'quantity' => $qty,
                    'unit_price' => Money::fromMinor($price, $currency),
                    'total' => Money::fromMinor($price * $qty, $currency),
                ]);
            }

            return $report;
        });
    }

    private function assertConsignment(Order $order): void
    {
        if (! $order->is_consignment) {
            throw ValidationException::withMessages(['order' => 'This is not a consignment order.']);
        }
    }
}
