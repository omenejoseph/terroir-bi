<?php

declare(strict_types=1);

namespace App\Queries;

use App\Enums\InventoryCategory;
use App\Models\InventoryItem;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Reusable, filterable listing of inventory items. The same query backs the API
 * and any future Livewire/Inertia table so filters/sorting live in one place.
 */
class ListInventoryItemsQuery
{
    /**
     * @param  array{search?: ?string, category?: ?string, is_for_sale?: ?bool, is_active?: ?bool, sellable?: ?bool, missing_cost?: ?bool, attention?: ?string}  $filters
     * @return LengthAwarePaginator<int, InventoryItem>
     */
    public function paginate(array $filters = [], int $perPage = 25): LengthAwarePaginator
    {
        // Groups and subcategories follow the order the organisation arranged them in (unarranged ones
        // last, alphabetically), then items by their own sort_order.
        return $this->build($filters)
            ->orderBy('category')
            ->orderByRaw($this->arrangement(subcategory: false))
            ->orderBy('group')
            ->orderByRaw($this->arrangement(subcategory: true))
            ->orderBy('subcategory')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->paginate($perPage);
    }

    /** The arranged position of an item's group (or subcategory) — 1000000 when never arranged. */
    private function arrangement(bool $subcategory): string
    {
        // `group` is a reserved word; let the connection's grammar quote it.
        $group = DB::getQueryGrammar()->wrap('inventory_items.group');

        if ($subcategory) {
            return "CASE WHEN COALESCE(inventory_items.subcategory, '') = '' THEN -1 ELSE COALESCE((SELECT o.sort_order FROM inventory_group_orders o"
                ." WHERE o.tenant_id = inventory_items.tenant_id AND o.category = inventory_items.category"
                ." AND o.group_name = COALESCE({$group}, '') AND o.subcategory = inventory_items.subcategory), 1000000) END";
        }

        return 'COALESCE((SELECT o.sort_order FROM inventory_group_orders o'
            .' WHERE o.tenant_id = inventory_items.tenant_id AND o.category = inventory_items.category'
            ." AND o.group_name = COALESCE({$group}, '') AND o.subcategory = ''), 1000000)";
    }

    /**
     * @param  array{search?: ?string, category?: ?string, is_for_sale?: ?bool, is_active?: ?bool, sellable?: ?bool, missing_cost?: ?bool, attention?: ?string}  $filters
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
