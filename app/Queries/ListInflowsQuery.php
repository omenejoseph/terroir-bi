<?php

declare(strict_types=1);

namespace App\Queries;

use App\Enums\InflowStatus;
use App\Models\Inflow;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

/**
 * Filterable listing of money-in records (payments / A/R).
 */
class ListInflowsQuery
{
    /**
     * @param  array{status?: ?string, customer_id?: ?string, order_id?: ?string, search?: ?string}  $filters
     * @return LengthAwarePaginator<int, Inflow>
     */
    public function paginate(array $filters = [], int $perPage = 25): LengthAwarePaginator
    {
        return $this->build($filters)
            ->with('order')
            ->withCount('changes')
            ->orderByDesc('date')
            ->paginate($perPage);
    }

    /**
     * @param  array{status?: ?string, customer_id?: ?string, order_id?: ?string, search?: ?string}  $filters
     * @return Builder<Inflow>
     */
    public function build(array $filters): Builder
    {
        $query = Inflow::query();

        if (! empty($filters['status'])) {
            $query->where('status', InflowStatus::from($filters['status']));
        }

        if (! empty($filters['customer_id'])) {
            $query->where('customer_id', $filters['customer_id']);
        }

        if (! empty($filters['order_id'])) {
            $query->where('order_id', $filters['order_id']);
        }

        if (! empty($filters['search'])) {
            $term = '%'.$filters['search'].'%';
            $query->where(fn (Builder $q) => $q->where('reference', 'like', $term)->orWhere('notes', 'like', $term));
        }

        return $query;
    }
}
