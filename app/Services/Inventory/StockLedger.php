<?php

declare(strict_types=1);

namespace App\Services\Inventory;

use App\Enums\StockMovementType;
use App\Models\InventoryItem;
use App\Models\StockMovement;
use App\Support\Quantity;
use Illuminate\Support\Facades\DB;

/**
 * The single entry point for changing stock. Every change writes a movement to
 * the ledger and updates the item's running current_stock in one transaction,
 * so the two never diverge.
 */
class StockLedger
{
    public function record(
        InventoryItem $item,
        StockMovementType $type,
        string $signedQuantity,
        ?string $reference = null,
        ?string $note = null,
    ): StockMovement {
        return DB::transaction(function () use ($item, $type, $signedQuantity, $reference, $note): StockMovement {
            $movement = $item->stockMovements()->create([
                'type' => $type,
                'quantity' => $signedQuantity,
                'unit' => $item->unit,
                'reference' => $reference,
                'note' => $note,
            ]);

            $item->current_stock = Quantity::add((string) $item->current_stock, $signedQuantity);
            $item->save();

            return $movement;
        });
    }
}
