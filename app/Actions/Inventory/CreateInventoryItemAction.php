<?php

declare(strict_types=1);

namespace App\Actions\Inventory;

use App\DataTransferObjects\InventoryItemData;
use App\Models\InventoryItem;

class CreateInventoryItemAction
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function execute(array $attributes): InventoryItemData
    {
        $item = InventoryItem::create($attributes);

        return InventoryItemData::fromModel($item);
    }
}
