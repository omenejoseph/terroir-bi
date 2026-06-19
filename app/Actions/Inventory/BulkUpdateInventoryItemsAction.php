<?php

declare(strict_types=1);

namespace App\Actions\Inventory;

use App\Models\InventoryItem;
use Illuminate\Support\Facades\DB;

/**
 * Apply per-row edits to many inventory items in one transaction. Each entry is
 * `['id' => ..., <field> => <value>, …]`; only the editable fields below are
 * honoured. Items are loaded tenant-scoped (global scope), so ids from another
 * tenant are silently skipped. Returns the number of rows updated.
 */
class BulkUpdateInventoryItemsAction
{
    /** Fields the bulk grid may change. Stock is excluded — it's ledger-driven. */
    private const EDITABLE = ['name', 'min_stock', 'default_price', 'cost_per_unit', 'is_active', 'is_for_sale'];

    /**
     * @param  list<array<string, mixed>>  $items
     */
    public function execute(array $items): int
    {
        return DB::transaction(function () use ($items): int {
            $byId = collect($items)->keyBy('id');
            $models = InventoryItem::query()->whereIn('id', $byId->keys())->get();

            foreach ($models as $model) {
                $changes = array_intersect_key($byId->get($model->getKey()) ?? [], array_flip(self::EDITABLE));
                $model->fill($changes)->save();
            }

            return $models->count();
        });
    }
}
