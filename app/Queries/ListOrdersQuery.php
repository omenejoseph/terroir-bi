<?php

declare(strict_types=1);

namespace App\Queries;

use App\Enums\OrderStatus;
use App\Models\Order;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

/**
 * Filterable order listing. Members without can_see_shipped_orders never see
 * SHIPPED orders (hide_shipped filter), set by the controller.
 */
class ListOrdersQuery
{
    /**
     * @param  array{status?: ?string, search?: ?string, hide_shipped?: ?bool}  $filters
     * @return LengthAwarePaginator<int, Order>
     */
    public function paginate(array $filters = [], int $perPage = 25): LengthAwarePaginator
    {
        return $this->build($filters)
            ->with(['customer', 'createdBy', 'items'])
            ->orderByDesc('created_at')
            ->paginate($perPage);
    }

    /**
     * @param  array{status?: ?string, search?: ?string, hide_shipped?: ?bool}  $filters
     * @return Builder<Order>
     */
    public function build(array $filters): Builder
    {
        $query = Order::query();

        if (! empty($filters['status'])) {
            $query->where('status', OrderStatus::from($filters['status']));
        }

        if (! empty($filters['hide_shipped'])) {
            $query->where('status', '!=', OrderStatus::Shipped);
        }

        if (! empty($filters['search'])) {
            $term = '%'.$filters['search'].'%';
            $query->where(function (Builder $q) use ($term) {
                $q->where('order_number', 'like', $term)
                    ->orWhereHas('customer', fn (Builder $c) => $c->where('company_name', 'like', $term));
            });
        }

        return $query;
    }
}
