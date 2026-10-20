<?php

declare(strict_types=1);

namespace App\Queries;

use App\Enums\InventoryCategory;
use App\Models\InventoryItem;
use App\Models\ProductCategory;
use App\Services\Inventory\InventoryVintageChains;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

/**
 * Reusable, filterable listing of inventory items. The same query backs the API
 * and any future Livewire/Inertia table so filters/sorting live in one place.
 */
class ListInventoryItemsQuery
{
    /**
     * @param  array{search?: ?string, category?: ?string, product_category_id?: ?string, is_for_sale?: ?bool, is_active?: ?bool, sellable?: ?bool, missing_cost?: ?bool, attention?: ?string}  $filters
     * @return LengthAwarePaginator<int, InventoryItem>
     */
    public function paginate(array $filters = [], int $perPage = 25): LengthAwarePaginator
    {
        return $this->arranged($this->build($filters))->paginate($perPage);
    }

    /**
     * The Inventory list: one row per product, showing its latest vintage — older vintages hang beneath
     * it (see InventoryVintageChains) rather than taking rows of their own. A filter that matches only an
     * older vintage still brings up the product it belongs to.
     *
     * @param  array{search?: ?string, category?: ?string, product_category_id?: ?string, is_for_sale?: ?bool, is_active?: ?bool, sellable?: ?bool, missing_cost?: ?bool, attention?: ?string}  $filters
     * @return LengthAwarePaginator<int, InventoryItem>
     */
    public function paginateProducts(array $filters = [], int $perPage = 25): LengthAwarePaginator
    {
        $narrowed = array_filter(
            array_diff_key($filters, ['category' => true]),
            fn (mixed $value): bool => $value !== null && $value !== '' && $value !== false,
        );

        if ($narrowed === []) {
            // Nothing is filtered out, so the heads are simply the items no newer vintage points at.
            $query = $this->build($filters)->whereNotExists(
                fn ($newer) => $newer->selectRaw('1')->from('inventory_items as newer')->whereColumn('newer.previous_vintage_id', 'inventory_items.id'),
            );
        } else {
            $matched = $this->build($filters)->pluck('id')->all();
            $query = InventoryItem::query()->whereIn('id', app(InventoryVintageChains::class)->headsOf($matched));
        }

        return $this->arranged($query)->paginate($perPage);
    }

    /**
     * The stocktake sheet: every active item in the same order as the Inventory list — category and
     * subcategory as arranged there — with each product's older vintages straight after its latest one,
     * since every vintage has to be counted on its own.
     *
     * @return list<array{item: InventoryItem, later: list<InventoryItem>, older: bool}> `later` is the vintages after this one in its chain
     */
    public function countSheet(): array
    {
        $heads = $this->arranged(
            $this->build(['is_active' => true])->whereNotExists(
                fn ($newer) => $newer->selectRaw('1')->from('inventory_items as newer')->whereColumn('newer.previous_vintage_id', 'inventory_items.id'),
            ),
        )->get();

        $older = app(InventoryVintageChains::class)->olderThan($heads);
        $rows = [];

        foreach ($heads as $head) {
            $chain = array_values(array_filter($older[$head->getKey()] ?? [], fn (InventoryItem $i): bool => $i->is_active));
            $rows[] = ['item' => $head, 'later' => $chain, 'older' => false];

            foreach ($chain as $at => $previous) {
                $rows[] = ['item' => $previous, 'later' => array_slice($chain, $at + 1), 'older' => true];
            }
        }

        return $rows;
    }

    /**
     * Categories and subcategories follow the order the organisation arranged them in, then items by
     * their own sort_order. Items with no category go last.
     *
     * @param  Builder<InventoryItem>  $query
     * @return Builder<InventoryItem>
     */
    private function arranged(Builder $query): Builder
    {
        return $query
            ->orderBy('category')
            ->orderByRaw($this->arrangement(subcategory: false))
            ->orderBy('group')
            ->orderByRaw($this->arrangement(subcategory: true))
            ->orderBy('subcategory')
            ->orderBy('sort_order')
            ->orderBy('name');
    }

    /** The arranged position of an item's category (or subcategory) — 1000000 when it has none. */
    private function arrangement(bool $subcategory): string
    {
        if ($subcategory) {
            return "CASE WHEN COALESCE(inventory_items.subcategory, '') = '' THEN -1 ELSE COALESCE((SELECT c.sort_order FROM product_categories c"
                .' WHERE c.id = inventory_items.product_category_id), 1000000) END';
        }

        // A subcategory sits where its parent does.
        return 'COALESCE((SELECT c.sort_order FROM product_categories c WHERE c.id = COALESCE('
            .'(SELECT l.parent_id FROM product_categories l WHERE l.id = inventory_items.product_category_id), inventory_items.product_category_id)), 1000000)';
    }

    /**
     * @param  array{search?: ?string, category?: ?string, product_category_id?: ?string, is_for_sale?: ?bool, is_active?: ?bool, sellable?: ?bool, missing_cost?: ?bool, attention?: ?string}  $filters
     * @return Builder<InventoryItem>
     */
    public function build(array $filters): Builder
    {
        $query = InventoryItem::query();

        if (! empty($filters['search'])) {
            $term = '%'.$filters['search'].'%';
            $query->where(function (Builder $q) use ($term) {
                $q->whereLike('name', $term)
                    ->orWhereLike('sku', $term);
            });
        }

        if (! empty($filters['category'])) {
            $query->where('category', InventoryCategory::from($filters['category']));
        }

        if (! empty($filters['product_category_id'])) {
            $id = (string) $filters['product_category_id'];
            $query->whereIn('product_category_id', ProductCategory::query()
                ->where('id', $id)->orWhere('parent_id', $id)->select('id'));
        }

        if (array_key_exists('is_active', $filters) && $filters['is_active'] !== null) {
            $query->where('is_active', $filters['is_active']);
        }

        if (array_key_exists('is_for_sale', $filters) && $filters['is_for_sale'] !== null) {
            $query->where('is_for_sale', $filters['is_for_sale']);
        }

        // "sellable": FINISHED, for sale, active, with a price set.
        if (! empty($filters['sellable'])) {
            $query->where('category', InventoryCategory::Finished)
                ->where('is_for_sale', true)
                ->where('is_active', true)
                ->whereNotNull('default_price');
        }

        // Inventory Analytics' "Add costs" deep-link — same "not costed"
        // criteria as InventoryAnalyticsQuery::get()'s costed_count.
        if (! empty($filters['missing_cost'])) {
            $query->where('is_active', true)->whereNull('cost_per_unit');
        }

        // A "Needs attention" chip: same definition as the chip's own count.
        if (! empty($filters['attention'])) {
            InventoryAttentionQuery::scope($query, (string) $filters['attention']);
        }

        return $query;
    }
}
