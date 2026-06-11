<?php

declare(strict_types=1);

namespace App\Actions\Inventory;

use App\Enums\StockMovementType;
use App\Models\InventoryCheck;
use App\Models\InventoryItem;
use App\Models\User;
use App\Services\Inventory\StockLedger;
use App\Support\Quantity;
use Illuminate\Support\Facades\DB;

/**
 * Apply a physical stocktake. For each counted item the difference against the
 * item's live stock is written as an ADJUSTMENT movement flagged
 * is_reconciliation = true (so it's excluded from spend/exit analytics). The
 * baseline is the server's current_stock, not a client-supplied figure, to
 * avoid acting on a stale count. The check (and its adjusted lines) is recorded
 * for audit when anything actually changed.
 */
class ApplyInventoryCheckAction
{
    public function __construct(private readonly StockLedger $ledger) {}

    /**
     * @param  list<array{item_id: string, physical_count: string}>  $counts
     * @return list<array{item_id: string, difference: string}>
     */
    public function execute(array $counts, ?User $performedBy = null): array
    {
        $reference = 'INVCHECK-'.now()->toDateString();

        return DB::transaction(function () use ($counts, $reference, $performedBy): array {
            $results = [];
            $lines = [];
            $net = '0';

            foreach ($counts as $count) {
                /** @var InventoryItem $item */
                $item = InventoryItem::query()->whereKey($count['item_id'])->lockForUpdate()->firstOrFail();

                $system = (string) $item->current_stock;
                $difference = Quantity::sub($count['physical_count'], $system);

                if (Quantity::compare($difference, '0') !== 0) {
                    $this->ledger->record(
                        $item,
                        StockMovementType::Adjustment,
                        $difference,
                        $reference,
                        'Stocktake adjustment',
                        isReconciliation: true,
                    );
                    $net = Quantity::add($net, $difference);
                    $lines[] = [
                        'inventory_item_id' => $item->getKey(),
                        'name' => $item->name,
                        'sku' => $item->sku,
                        'system_count' => $system,
                        'physical_count' => Quantity::normalize($count['physical_count']),
                        'difference' => $difference,
                    ];
                }

                $results[] = ['item_id' => $item->getKey(), 'difference' => $difference];
            }

            if ($lines !== []) {
                $check = InventoryCheck::create([
                    'performed_by_id' => $performedBy?->getKey(),
                    'reference' => $reference,
                    'items_counted' => count($counts),
                    'items_adjusted' => count($lines),
                    'net_difference' => $net,
                ]);
                foreach ($lines as $line) {
                    $check->lines()->create($line);
                }
            }

            return $results;
        });
    }
}
