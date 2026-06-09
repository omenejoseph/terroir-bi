<?php

declare(strict_types=1);

namespace App\Queries;

use App\Models\InventoryItem;

/**
 * Distinct category → group → subcategory combinations currently in use within
 * the tenant. Powers the creatable group/subcategory autocompletes and the
 * grouped inventory listing. Groups/subcategories are free-text on items, so the
 * "taxonomy" is simply what already exists.
 */
class InventoryTaxonomyQuery
{
    /**
     * @return array<int, array{category: string, group: string, subcategory: string|null}>
     */
    public function get(): array
    {
        return InventoryItem::query()
            ->whereNotNull('group')
            ->orderBy('category')
            ->orderBy('group')
            ->orderBy('subcategory')
            ->get(['category', 'group', 'subcategory'])
            ->map(fn (InventoryItem $item): array => [
                'category' => $item->category->value,
                'group' => (string) $item->group,
                'subcategory' => $item->subcategory,
            ])
            ->unique(fn (array $row): string => $row['category'].'|'.$row['group'].'|'.($row['subcategory'] ?? ''))
            ->values()
            ->all();
    }
}
