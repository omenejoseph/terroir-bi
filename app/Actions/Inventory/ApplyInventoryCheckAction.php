<?php

declare(strict_types=1);

namespace App\Actions\Inventory;

use App\Enums\StockMovementType;
use App\Models\InventoryItem;
use App\Services\Inventory\StockLedger;
use App\Support\Quantity;
use Illuminate\Support\Facades\DB;

/**
 * Apply a physical stocktake. For each counted item the difference against the
 * item's live stock is written as an ADJUSTMENT movement flagged
 * is_reconciliation = true (so it's excluded from spend/exit analytics). The
 * baseline is the server's current_stock, not a client-supplied figure, to
 * avoid acting on a stale count.
 */
class ApplyInventoryCheckAction
{
    public function __construct(private readonly StockLedger $ledger) {}

    /**
     * @param  list<array{item_id: string, physical_count: string}>  $counts
     * @return list<array{item_id: string, difference: string}>
     */
    public function execute(array $counts): array
    {
        $reference = 'INVCHECK-'.now()->toDateString();

        return DB::transaction(function () use ($counts, $reference): array {
            $results = [];

            foreach ($counts as $count) {
                /** @var InventoryItem $item */
                $item = InventoryItem::query()->whereKey($count['item_id'])->lockForUpdate()->firstOrFail();

                $difference = Quantity::sub($count['physical_count'], (string) $item->current_stock);

                if (Quantity::compare($difference, '0') !== 0) {
                    $this->ledger->record(
                        $item,
                        StockMovementType::Adjustment,
                        $difference,
                        $reference,
                        'Stocktake adjustment',
                        isReconciliation: true,
                    );
                }

                $results[] = ['item_id' => $item->getKey(), 'difference' => $difference];
            }

            return $results;
        });
    }
}
