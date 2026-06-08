<?php

declare(strict_types=1);

namespace App\Actions\Inventory;

use App\DataTransferObjects\InventoryItemData;
use App\Models\InventoryItem;

class UpdateInventoryItemAction
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function execute(InventoryItem $item, array $attributes): InventoryItemData
    {
        $item->fill($attributes)->save();

        return InventoryItemData::fromModel($item);
    }
}
