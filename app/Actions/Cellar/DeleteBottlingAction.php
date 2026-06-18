<?php

declare(strict_types=1);

namespace App\Actions\Cellar;

use App\Enums\StockMovementType;
use App\Enums\WineLotStatus;
use App\Models\Bottling;
use App\Models\InventoryItem;
use App\Services\Inventory\StockLedger;
use App\Support\Quantity;
use Illuminate\Support\Facades\DB;

/**
 * Reverse a bottling: return the bottled volume to the lot (re-opening it from
 * BOTTLED if needed) and pull the finished bottles back out of inventory stock.
 */
class DeleteBottlingAction
{
    public function __construct(private readonly StockLedger $ledger) {}

    public function execute(Bottling $bottling): void
    {
        DB::transaction(function () use ($bottling): void {
            $lot = $bottling->wineLot()->lockForUpdate()->firstOrFail();

            $lot->current_volume = Quantity::add((string) $lot->current_volume, (string) $bottling->volume_used);
            if ($lot->status === WineLotStatus::Bottled) {
                $lot->status = WineLotStatus::Ready;
            }
            $lot->save();

            if ($bottling->inventory_item_id !== null) {
                $item = InventoryItem::query()->whereKey($bottling->inventory_item_id)->first();
                if ($item !== null) {
                    $this->ledger->record(
                        $item,
                        StockMovementType::ProductionOut,
                        '-'.$bottling->bottle_count,
                        "BOTTLE-{$lot->lot_number}-REVERSE",
                    );
                }
            }

            $bottling->delete();
        });
    }
}
