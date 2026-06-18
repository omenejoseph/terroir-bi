<?php

declare(strict_types=1);

namespace App\Actions\Cellar;

use App\Enums\StockMovementType;
use App\Enums\WineLotStatus;
use App\Models\Bottling;
use App\Models\InventoryItem;
use App\Models\WineLot;
use App\Services\Cellar\LotCostService;
use App\Services\Cellar\LotVolumeService;
use App\Services\Cellar\VesselVolumeSync;
use App\Services\Inventory\StockLedger;
use App\Support\Money\CurrencyRegistry;
use App\Support\Money\Money;
use App\Support\Quantity;
use Illuminate\Support\Facades\DB;

/**
 * Bottle a lot: draw the bottled volume out of the lot (and its vessels), record
 * the run, and — when a finished inventory item is given — add that many bottles
 * to stock via the StockLedger with a per-bottle cost snapshot. When the lot is
 * emptied it is marked BOTTLED and any lingering vessel occupancy is cleared.
 */
class CreateBottlingAction
{
    public function __construct(
        private readonly LotCostService $costs,
        private readonly StockLedger $ledger,
        private readonly VesselVolumeSync $sync,
        private readonly LotVolumeService $volumes,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function execute(WineLot $lot, array $data, string $createdById): Bottling
    {
        return DB::transaction(function () use ($lot, $data, $createdById): Bottling {
            $bottleCount = (int) $data['bottle_count'];
            $bottleMl = (int) ($data['bottle_volume_ml'] ?? 750);
            $volumeUsed = Quantity::normalize((string) ($bottleCount * $bottleMl / 1000));

            $itemId = $data['inventory_item_id'] ?? null;
            $item = $itemId !== null ? InventoryItem::query()->whereKey((string) $itemId)->first() : null;

            // Snapshot per-bottle cost before draining changes nothing (cost is on
            // initial volume), then draw the wine out of the lot + its vessels.
            $costPerBottle = $this->costs->costPerBottle($lot, $bottleMl);
            $this->volumes->drain($lot, $volumeUsed);

            /** @var Bottling $bottling */
            $bottling = $lot->bottlings()->create([
                'inventory_item_id' => $item?->getKey(),
                'created_by_id' => $createdById,
                'bottle_count' => $bottleCount,
                'bottle_volume_ml' => $bottleMl,
                'volume_used' => $volumeUsed,
                'date' => $data['date'] ?? now(),
                'note' => $data['note'] ?? null,
            ]);

            // Add finished stock and snapshot cost on the produced item.
            if ($item !== null) {
                $this->ledger->record(
                    $item,
                    StockMovementType::ProductionIn,
                    (string) $bottleCount,
                    "BOTTLE-{$lot->lot_number}",
                );
                if ($item->cost_per_unit === null && $costPerBottle > 0) {
                    // Only the minor amount is persisted; currency is resolved on read.
                    $item->cost_per_unit = Money::fromMinor($costPerBottle, CurrencyRegistry::default()->code);
                    $item->save();
                }
            }

            // Emptied → mark bottled and free any remaining vessels.
            if (Quantity::compare((string) $lot->fresh()?->current_volume, '0.001') <= 0) {
                $vesselIds = $lot->vesselLots()->pluck('vessel_id')->all();
                $lot->vesselLots()->delete();
                $lot->status = WineLotStatus::Bottled;
                $lot->current_volume = '0.000';
                $lot->save();
                $this->sync->syncMany($vesselIds);
            }

            return $bottling;
        });
    }
}
