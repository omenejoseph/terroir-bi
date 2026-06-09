<?php

declare(strict_types=1);

namespace App\Actions\Inventory;

use App\DataTransferObjects\InventoryItemData;
use App\Enums\StockMovementType;
use App\Models\InventoryItem;
use App\Services\Inventory\StockLedger;

/**
 * Records a stock movement (signed quantity) and returns the item with its new
 * running stock.
 */
class AdjustStockAction
{
    public function __construct(private readonly StockLedger $ledger) {}

    public function execute(
        InventoryItem $item,
        StockMovementType $type,
        string $signedQuantity,
        ?string $reference = null,
        ?string $note = null,
        bool $isReconciliation = false,
    ): InventoryItemData {
        $this->ledger->record($item, $type, $signedQuantity, $reference, $note, $isReconciliation);

        return InventoryItemData::fromModel($item->refresh());
    }
}
