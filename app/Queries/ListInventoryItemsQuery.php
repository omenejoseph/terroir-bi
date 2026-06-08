<?php

declare(strict_types=1);

namespace App\Queries;

use App\Enums\InventoryCategory;
use App\Models\InventoryItem;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

/**
 * Reusable, filterable listing of inventory items. The same query backs the API
 * and any future Livewire/Inertia table so filters/sorting live in one place.
 */
class ListInventoryItemsQuery
{
    /**
     * @param  array{search?: ?string, category?: ?string, is_for_sale?: ?bool, is_active?: ?bool, sellable?: ?bool}  $filters
     * @return LengthAwarePaginator<int, InventoryItem>
     */
    public function paginate(array $filters = [], int $perPage = 25): LengthAwarePaginator
    {
        return $this->build($filters)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->paginate($perPage);
    }

    /**
     * @param  array{search?: ?string, category?: ?string, is_for_sale?: ?bool, is_active?: ?bool, sellable?: ?bool}  $filters
     * @return Builder<InventoryItem>
     */
    public function build(array $filters): Builder
    {
        $query = InventoryItem::query();

        if (! empty($filters['search'])) {
            $term = '%'.$filters['search'].'%';
            $query->where(function (Builder $q) use ($term) {
                $q->where('name', 'like', $term)
                    ->orWhere('sku', 'like', $term);
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

        return $query;
    }
}
