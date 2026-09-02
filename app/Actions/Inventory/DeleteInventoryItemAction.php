<?php

declare(strict_types=1);

namespace App\Actions\Inventory;

use App\Models\InventoryItem;

/**
 * Removes an inventory item, preserving order history.
 *
 * An item referenced by an order line is deactivated rather than deleted, so
 * historic orders keep resolving their lines; anything else is hard-deleted
 * (cascading recipe lines and stock movements).
 *
 * Shared by the API and Inertia controllers so the two cannot diverge on what
 * "delete" means.
 */
class DeleteInventoryItemAction
{
    /**
     * @return bool True when the item was deactivated instead of deleted.
     */
    public function execute(InventoryItem $item): bool
    {
        if ($item->orderItems()->exists()) {
            $item->is_active = false;
            $item->save();

            return true;
        }

        $item->delete();

        return false;
    }
}
