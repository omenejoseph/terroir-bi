<?php

declare(strict_types=1);

namespace App\Actions\Inventory;

use App\Models\ProductCategory;

class CreateProductCategoryAction
{
    /**
     * @param  array{name: string, type?: string, parent_id?: ?string, has_vintage?: bool}  $attributes
     */
    public function execute(array $attributes): ProductCategory
    {
        $parent = ($attributes['parent_id'] ?? null) !== null
            ? ProductCategory::query()->findOrFail($attributes['parent_id'])
            : null;

        $type = $attributes['type'] ?? null;

        $next = ProductCategory::query()
            ->when($parent === null, fn ($q) => $q->whereNull('parent_id'), fn ($q) => $q->where('parent_id', $parent?->getKey()))
            ->when($parent === null, fn ($q) => $q->where('type', $type))
            ->max('sort_order');

        return ProductCategory::create([
            'parent_id' => $parent?->getKey(),
            // A subcategory lives under its parent's type.
            'type' => $parent !== null ? $parent->type->value : $type,
            'name' => trim($attributes['name']),
            'has_vintage' => $attributes['has_vintage'] ?? $parent->has_vintage ?? false,
            'sort_order' => $next === null ? 0 : $next + 1,
        ]);
    }
}
