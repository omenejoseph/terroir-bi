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
     * @param  array{status?: ?string, search?: ?string, hide_shipped?: ?bool, customer_id?: ?string, from?: ?string, to?: ?string}  $filters
     * @return LengthAwarePaginator<int, Order>
     */
    public function paginate(array $filters = [], int $perPage = 25): LengthAwarePaginator
    {
        return $this->build($filters)
            ->with(['customer', 'createdBy', 'items.inventoryItem'])
            ->orderByDesc('created_at')
            ->paginate($perPage);
    }

    /**
     * @param  array{status?: ?string, search?: ?string, hide_shipped?: ?bool, customer_id?: ?string, from?: ?string, to?: ?string}  $filters
     * @return Builder<Order>
     */
    public function build(array $filters): Builder
    {
        $query = Order::query();

        if (! empty($filters['customer_id'])) {
            $query->where('customer_id', $filters['customer_id']);
        }

        // The Orders list's period tabs (Figma 455:1577) narrow the table as
        // well as the pipeline card, so the bounds belong on the shared query
        // rather than only on the page that draws the card.
        if (! empty($filters['from'])) {
            $query->where('created_at', '>=', $filters['from']);
        }

        if (! empty($filters['to'])) {
            $query->where('created_at', '<=', $filters['to']);
        }

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
