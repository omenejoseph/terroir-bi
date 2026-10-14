<?php

declare(strict_types=1);

namespace App\Actions\Cellar;

use App\Enums\StockMovementType;
use App\Enums\WineLotStatus;
use App\Models\Bottling;
use App\Models\InventoryItem;
use App\Models\Vessel;
use App\Services\Audit\AuditLogger;
use App\Services\Cellar\LotVolumeService;
use App\Services\Inventory\StockLedger;
use App\Support\Quantity;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Reverse a bottling: return the bottled volume to the lot (re-opening it from
 * BOTTLED if needed) and to the vessels it came from where they still have
 * room, and pull the finished bottles back out of inventory stock.
 */
class DeleteBottlingAction
{
    public function __construct(
        private readonly StockLedger $ledger,
        private readonly LotVolumeService $volumes,
        private readonly AuditLogger $audit,
    ) {}

    public function execute(Bottling $bottling): void
    {
        DB::transaction(function () use ($bottling): void {
            $lot = $bottling->wineLot()->lockForUpdate()->firstOrFail();

            $lot->current_volume = Quantity::add((string) $lot->current_volume, (string) $bottling->volume_used);
            if ($lot->status === WineLotStatus::Bottled) {
                $lot->status = WineLotStatus::Ready;
            }
            $lot->save();

            // Put the wine back in its vessels (skipping any that filled up since).
            $restored = [];
            foreach ($bottling->vessels()->get() as $bv) {
                $vessel = Vessel::query()->whereKey($bv->vessel_id)->first();
                if ($vessel === null) {
                    continue;
                }
                $free = Quantity::sub((string) $vessel->capacity_liters, (string) $vessel->current_volume);
                if (Quantity::compare((string) $bv->volume, $free) <= 0) {
                    $this->volumes->assignToVessel($lot, $vessel, (string) $bv->volume);
                    $restored[] = ['vessel_id' => $bv->vessel_id, 'volume' => (string) $bv->volume];
                }
            }

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

            $this->audit->record(Auth::user(), 'bottling.reversed', $bottling, [
                'wine_lot_id' => $lot->getKey(),
                'bottle_count' => $bottling->bottle_count,
                'volume_used' => (string) $bottling->volume_used,
                'restored_to_vessels' => $restored,
            ]);

            $bottling->delete();
        });
    }
}
