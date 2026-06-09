<?php

declare(strict_types=1);

namespace App\Actions\Suppliers;

use App\Models\Supplier;
use App\Models\SupplierPriceItem;

/**
 * Upsert a supplier price-list line, keyed on (supplier, description). Reused by
 * the price-list "learning" path when costs/invoices are imported.
 */
class UpsertSupplierPriceItemAction
{
    /**
     * @param  array{description: string, unit_price: int, unit?: ?string, notes?: ?string, inventory_item_id?: ?string}  $attributes
     */
    public function execute(Supplier $supplier, array $attributes): SupplierPriceItem
    {
        return SupplierPriceItem::updateOrCreate(
            ['supplier_id' => $supplier->getKey(), 'description' => $attributes['description']],
            [
                'unit_price' => $attributes['unit_price'],
                'unit' => $attributes['unit'] ?? null,
                'notes' => $attributes['notes'] ?? null,
                'inventory_item_id' => $attributes['inventory_item_id'] ?? null,
                'last_updated' => now(),
            ],
        );
    }
}
