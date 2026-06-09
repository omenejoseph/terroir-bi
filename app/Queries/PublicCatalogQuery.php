<?php

declare(strict_types=1);

namespace App\Queries;

use App\Enums\InventoryCategory;
use App\Models\Customer;
use App\Models\InventoryItem;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * The items a customer may order through the self-service portal: active,
 * FINISHED, priced items that are for-sale and not portal-hidden — adjusted by
 * the customer's per-item visibility overrides (force-show / force-hide).
 */
class PublicCatalogQuery
{
    /**
     * @return Collection<int, InventoryItem>
     */
    public function forCustomer(Customer $customer): Collection
    {
        $overrides = $customer->productOverrides()->get();
        $forceShow = $overrides->where('visible', true)->pluck('inventory_item_id')->all();
        $forceHide = $overrides->where('visible', false)->pluck('inventory_item_id')->all();

        return InventoryItem::query()
            ->where('is_active', true)
            ->where('category', InventoryCategory::Finished)
            ->whereNotNull('default_price')
            ->where(function (Builder $q) use ($forceShow) {
                $q->where(fn (Builder $sub) => $sub->where('is_for_sale', true)->where('hide_from_portal', false))
                    ->orWhereIn('id', $forceShow);
            })
            ->whereNotIn('id', $forceHide)
            ->orderBy('group')
            ->orderBy('subcategory')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();
    }
}
