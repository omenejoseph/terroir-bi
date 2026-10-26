<?php

declare(strict_types=1);

namespace App\Services\Kitchen;

use App\Enums\KitchenMovementType;
use App\Models\KitchenItem;
use App\Models\KitchenMovement;
use App\Support\Quantity;
use Illuminate\Support\Facades\DB;

/**
 * The one way a kitchen item's stock changes (docs/21 §3):
 *  - IN adds the quantity, OUT takes it away — with no guard against going negative, as in the old
 *    app and as the enological stock does, because the kitchen uses things before anyone books them;
 *  - ADJUSTMENT takes the new absolute figure and records the difference.
 * Every change writes a movement carrying the signed difference.
 */
class KitchenStockLedger
{
    public function move(KitchenItem $item, KitchenMovementType $type, string $quantity, ?string $note, string $userId): KitchenMovement
    {
        return DB::transaction(function () use ($item, $type, $quantity, $note, $userId): KitchenMovement {
            // Re-read under a lock so two people booking at once both land.
            $locked = KitchenItem::query()->lockForUpdate()->whereKey($item->getKey())->firstOrFail();
            $current = Quantity::normalize((string) $locked->current_stock);
            $quantity = Quantity::normalize($quantity);

            $delta = match ($type) {
                KitchenMovementType::In => $quantity,
                KitchenMovementType::Out => Quantity::negate($quantity),
                KitchenMovementType::Adjustment => Quantity::sub($quantity, $current),
            };

            $locked->forceFill(['current_stock' => Quantity::add($current, $delta)])->save();
            $item->setRawAttributes($locked->getAttributes(), true);

            return KitchenMovement::create([
                'item_id' => $item->getKey(),
                'type' => $type,
                'quantity' => $delta,
                'note' => $note,
                'user_id' => $userId,
            ]);
        });
    }
}
