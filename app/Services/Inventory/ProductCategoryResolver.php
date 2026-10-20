<?php

declare(strict_types=1);

namespace App\Services\Inventory;

use App\Models\ProductCategory;

/**
 * Finds the category a free-text group / subcategory names, creating it when it does not exist yet.
 * This is what keeps the older ways of naming a category — the API, bulk import, production cloning a
 * vintage — working now that categories are records: they name one, and it is found or made.
 */
class ProductCategoryResolver
{
    /** The leaf category (the subcategory when there is one, else the group) — null when no group is named. */
    public function resolve(string $type, ?string $group, ?string $subcategory): ?ProductCategory
    {
        $group = $this->clean($group);
        if ($group === null) {
            return null;
        }

        $top = $this->findOrCreate($type, null, $group);

        $sub = $this->clean($subcategory);

        return $sub === null ? $top : $this->findOrCreate($type, $top, $sub);
    }

    private function findOrCreate(string $type, ?ProductCategory $parent, string $name): ProductCategory
    {
        $existing = ProductCategory::query()
            ->where('type', $type)
            ->when($parent === null, fn ($q) => $q->whereNull('parent_id'), fn ($q) => $q->where('parent_id', $parent?->getKey()))
            ->get()
            ->first(fn (ProductCategory $c): bool => mb_strtolower($c->name) === mb_strtolower($name));

        if ($existing !== null) {
            return $existing;
        }

        $next = ProductCategory::query()
            ->where('type', $type)
            ->when($parent === null, fn ($q) => $q->whereNull('parent_id'), fn ($q) => $q->where('parent_id', $parent?->getKey()))
            ->max('sort_order');

        return ProductCategory::create([
            'parent_id' => $parent?->getKey(),
            'type' => $type,
            'name' => $name,
            // A subcategory starts out like its parent.
            'has_vintage' => $parent?->has_vintage ?? false,
            'sort_order' => $next === null ? 0 : $next + 1,
        ]);
    }

    private function clean(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
