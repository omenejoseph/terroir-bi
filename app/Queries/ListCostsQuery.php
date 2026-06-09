<?php

declare(strict_types=1);

namespace App\Queries;

use App\Enums\CostStatus;
use App\Models\Cost;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

class ListCostsQuery
{
    /**
     * @param  array{search?: ?string, category?: ?string, status?: ?string, supplier_id?: ?string}  $filters
     * @return LengthAwarePaginator<int, Cost>
     */
    public function paginate(array $filters = [], int $perPage = 25): LengthAwarePaginator
    {
        return $this->build($filters)->with('supplier')->orderByDesc('date')->paginate($perPage);
    }

    /**
     * @param  array{search?: ?string, category?: ?string, status?: ?string, supplier_id?: ?string}  $filters
     * @return Builder<Cost>
     */
    public function build(array $filters): Builder
    {
        $query = Cost::query();

        if (! empty($filters['search'])) {
            $term = '%'.$filters['search'].'%';
            $query->where(fn (Builder $q) => $q->where('description', 'like', $term)
                ->orWhere('reference', 'like', $term)
                ->orWhere('category', 'like', $term));
        }

        if (! empty($filters['category'])) {
            $query->where('category', $filters['category']);
        }

        if (! empty($filters['status'])) {
            $query->where('status', CostStatus::from($filters['status']));
        }

        if (! empty($filters['supplier_id'])) {
            $query->where('supplier_id', $filters['supplier_id']);
        }

        return $query;
    }
}
