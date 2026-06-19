<?php

declare(strict_types=1);

namespace App\Actions\Inventory;

use App\DataTransferObjects\InventoryItemData;
use App\Models\InventoryItem;
use Illuminate\Support\Facades\DB;

/**
 * Clone an inventory item into a new draft: same attributes with a " (Copy)"
 * name and a unique SKU, stock reset to zero (stock is ledger-driven, never
 * copied), and the recipe (bill of materials) duplicated. Media, analyses and
 * stock movements are intentionally not copied.
 */
class DuplicateInventoryItemAction
{
    public function execute(InventoryItem $item): InventoryItemData
    {
        return DB::transaction(function () use ($item): InventoryItemData {
            $copy = $item->replicate(['current_stock', 'is_auto_created', 'auto_created_at']);
            $copy->name = $item->name.' (Copy)';
            $copy->sku = $this->uniqueSku($item->sku);
            $copy->current_stock = '0';
            $copy->is_auto_created = false;
            $copy->auto_created_at = null;
            $copy->save();

            // Duplicate the recipe (bill of materials) verbatim onto the copy.
            foreach ($item->recipe as $line) {
                $copy->recipe()->create([
                    'input_id' => $line->input_id,
                    'quantity' => $line->quantity,
                    'custom_name' => $line->custom_name,
                    'custom_unit' => $line->custom_unit,
                    'custom_cost' => $line->custom_cost,
                ]);
            }

            return InventoryItemData::fromModel($copy->refresh());
        });
    }

    /** First free SKU of the form "{base}-COPY", "{base}-COPY-2", … (tenant-scoped). */
    private function uniqueSku(string $base): string
    {
        $candidate = "{$base}-COPY";
        $suffix = 2;
        while (InventoryItem::query()->where('sku', $candidate)->exists()) {
            $candidate = "{$base}-COPY-{$suffix}";
            $suffix++;
        }

        return $candidate;
    }
}
