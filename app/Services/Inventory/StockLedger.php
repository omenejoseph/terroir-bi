<?php

declare(strict_types=1);

namespace App\Services\Inventory;

use App\Enums\SalesUnit;
use App\Enums\StockMovementType;
use App\Exceptions\InsufficientStockException;
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
        bool $isReconciliation = false,
    ): StockMovement {
        return DB::transaction(function () use ($item, $type, $signedQuantity, $reference, $note, $isReconciliation): StockMovement {
            $movement = $item->stockMovements()->create([
                'type' => $type,
                'quantity' => $signedQuantity,
                'unit' => $item->unit,
                'reference' => $reference,
                'note' => $note,
                'is_reconciliation' => $isReconciliation,
            ]);

            $item->current_stock = Quantity::add((string) $item->current_stock, $signedQuantity);
            $item->save();

            return $movement;
        });
    }

    /**
     * Deduct stock for an order line. Quantity is given in the order's display
     * unit (bottles/cases); it is converted to the item's storage unit, the row
     * is locked, and the deduction is refused if it would drive stock negative.
     * Backorders must skip this path entirely.
     *
     * @throws InsufficientStockException
     */
    public function deduct(
        InventoryItem $item,
        string $quantity,
        string $unitType,
        ?string $reference = null,
        ?string $note = null,
    ): StockMovement {
        return DB::transaction(function () use ($item, $quantity, $unitType, $reference, $note): StockMovement {
            $locked = $this->lock($item);
            $storageQty = $this->toStorageQuantity($locked, $quantity, $unitType);

            return $this->withdrawLocked($locked, $storageQty, StockMovementType::OrderDeduct, $reference, $note);
        });
    }

    /**
     * Guarded outflow already expressed in the item's storage unit (no
     * bottles/cases conversion) — e.g. consuming a recipe input in production.
     * Locks the row and refuses to drive stock negative.
     *
     * @throws InsufficientStockException
     */
    public function withdraw(
        InventoryItem $item,
        string $storageQuantity,
        StockMovementType $type = StockMovementType::ProductionOut,
        ?string $reference = null,
        ?string $note = null,
    ): StockMovement {
        return DB::transaction(function () use ($item, $storageQuantity, $type, $reference, $note): StockMovement {
            $locked = $this->lock($item);

            return $this->withdrawLocked($locked, Quantity::normalize($storageQuantity), $type, $reference, $note);
        });
    }

    /** Shared guard+record for a positive storage quantity being removed. */
    private function withdrawLocked(
        InventoryItem $locked,
        string $storageQty,
        StockMovementType $type,
        ?string $reference,
        ?string $note,
    ): StockMovement {
        if (Quantity::compare((string) $locked->current_stock, $storageQty) < 0) {
            throw InsufficientStockException::for($locked, $storageQty);
        }

        return $this->record($locked, $type, Quantity::negate($storageQty), $reference, $note);
    }

    /**
     * Put stock back (order/line delete, consignment return). Quantity is in the
     * display unit; converted to storage unit and added. No guard — restores only
     * ever increase stock.
     */
    public function restore(
        InventoryItem $item,
        string $quantity,
        string $unitType,
        StockMovementType $type = StockMovementType::ManualIn,
        ?string $reference = null,
        ?string $note = null,
    ): StockMovement {
        return DB::transaction(function () use ($item, $quantity, $unitType, $type, $reference, $note): StockMovement {
            $locked = $this->lock($item);
            $storageQty = $this->toStorageQuantity($locked, $quantity, $unitType);

            return $this->record($locked, $type, $storageQty, $reference, $note);
        });
    }

    /** Re-read the row under a write lock so the guard sees authoritative stock. */
    private function lock(InventoryItem $item): InventoryItem
    {
        /** @var InventoryItem $locked */
        $locked = InventoryItem::query()->whereKey($item->getKey())->lockForUpdate()->firstOrFail();

        return $locked;
    }

    /**
     * Convert a quantity in the order's display unit (bottles/cases) to the
     * item's storage unit, bridging through bottles via bottles_per_case.
     * Unknown/equal units pass through unchanged.
     */
    private function toStorageQuantity(InventoryItem $item, string $quantity, string $unitType): string
    {
        $storageUnit = (string) $item->unit;

        if ($unitType === $storageUnit) {
            return Quantity::normalize($quantity);
        }

        $bottlesPerCase = max(1, (int) $item->bottles_per_case);

        $bottles = $unitType === SalesUnit::Cases->value
            ? Quantity::mulInt($quantity, $bottlesPerCase)
            : Quantity::normalize($quantity);

        return $storageUnit === SalesUnit::Cases->value
            ? Quantity::divInt($bottles, $bottlesPerCase)
            : $bottles;
    }
}
