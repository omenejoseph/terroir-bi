<?php

declare(strict_types=1);

namespace App\Services\Inventory;

use App\Models\InventoryItem;
use App\Models\ProductCategory;

/**
 * The category tree as the Inventory screens use it: the item form's Category / Subcategory selects
 * and the Categories tab share it. Item counts include a top-level category's subcategories, so the
 * number answers "can this be deleted?".
 */
class ProductCategoryOptions
{
    /**
     * @return list<array{id: string, name: string, type: string, has_vintage: bool, sort_order: int, items_count: int, children: list<array{id: string, name: string, type: string, has_vintage: bool, sort_order: int, items_count: int}>}>
     */
    public function tree(): array
    {
        $counts = InventoryItem::query()
            ->whereNotNull('product_category_id')
            ->selectRaw('product_category_id, count(*) as aggregate')
            ->groupBy('product_category_id')
            ->pluck('aggregate', 'product_category_id');

        $all = ProductCategory::query()->orderBy('sort_order')->orderBy('name')->get();
        $row = fn (ProductCategory $c, int $count): array => [
            'id' => (string) $c->getKey(),
            'name' => $c->name,
            'type' => $c->type->value,
            'has_vintage' => $c->has_vintage,
            'sort_order' => $c->sort_order,
            'items_count' => $count,
        ];

        $tree = [];
        foreach ($all->whereNull('parent_id') as $top) {
            $children = $all->where('parent_id', $top->getKey())
                ->map(fn (ProductCategory $sub): array => $row($sub, (int) ($counts[$sub->getKey()] ?? 0)))
                ->values()
                ->all();

            $own = (int) ($counts[$top->getKey()] ?? 0);
            $tree[] = $row($top, $own + array_sum(array_column($children, 'items_count'))) + ['children' => $children];
        }

        return $tree;
    }

    /**
     * Items that can be named as another item's previous vintage: those in a category that carries
     * vintages. `has_next` marks the ones already followed by another vintage — each can only be
     * the previous of one, so the picker hides them unless one is the item being edited right now.
     *
     * @return list<array{id: string, name: string, vintage: ?string, unit_size: ?string, type: string, product_category_id: string, has_next: bool}>
     */
    public function vintageCandidates(): array
    {
        $vintageCategoryIds = ProductCategory::query()->where('has_vintage', true)->pluck('id');
        $followed = InventoryItem::query()->whereNotNull('previous_vintage_id')->pluck('previous_vintage_id')->flip();

        return InventoryItem::query()
            ->whereIn('product_category_id', $vintageCategoryIds)
            ->orderBy('name')
            ->orderBy('vintage')
            ->get(['id', 'name', 'vintage', 'unit_size', 'category', 'product_category_id'])
            ->map(fn (InventoryItem $i): array => [
                'id' => (string) $i->getKey(),
                'name' => $i->name,
                'vintage' => $i->vintage,
                'unit_size' => $i->unit_size,
                'type' => $i->category->value,
                'product_category_id' => (string) $i->product_category_id,
                'has_next' => $followed->has($i->getKey()),
            ])
            ->values()
            ->all();
    }
}
