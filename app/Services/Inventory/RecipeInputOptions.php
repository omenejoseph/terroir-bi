<?php

declare(strict_types=1);

namespace App\Services\Inventory;

use App\Models\InventoryItem;

/**
 * The Recipe tab's "add ingredient" picker (Product Detail, Figma 449:1577):
 * any other active catalog item — a bill of materials draws on raw and
 * semi-finished stock, not just sellable finished goods, so this is wider
 * than App\Services\Orders\OrderFormOptions::products().
 */
class RecipeInputOptions
{
    /**
     * @return list<array<string, mixed>>
     */
    public function list(InventoryItem $excluding): array
    {
        return array_values(InventoryItem::query()
            ->where('is_active', true)
            ->whereKeyNot($excluding->getKey())
            ->orderBy('name')
            ->get(['id', 'name', 'sku', 'vintage', 'unit', 'group'])
            ->map(fn (InventoryItem $item): array => [
                'id' => $item->getKey(),
                'name' => $item->name,
                'sku' => $item->sku,
                'vintage' => $item->vintage,
                'unit' => $item->unit,
                'group' => $item->group,
            ])
            ->all());
    }
}
