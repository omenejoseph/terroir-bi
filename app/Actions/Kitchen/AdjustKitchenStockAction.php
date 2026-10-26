<?php

declare(strict_types=1);

namespace App\Actions\Kitchen;

use App\Enums\KitchenMovementType;
use App\Models\KitchenItem;
use App\Models\KitchenMovement;
use App\Services\Kitchen\KitchenStockLedger;

class AdjustKitchenStockAction
{
    public function __construct(private readonly KitchenStockLedger $ledger) {}

    public function execute(KitchenItem $item, KitchenMovementType $type, string $quantity, ?string $note, string $userId): KitchenMovement
    {
        return $this->ledger->move($item, $type, $quantity, $note, $userId);
    }
}
