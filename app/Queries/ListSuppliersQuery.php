<?php

declare(strict_types=1);

namespace App\Queries;

use App\Models\Supplier;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

class ListSuppliersQuery
{
    /**
     * @param  array{search?: ?string, is_active?: ?bool}  $filters
     * @return LengthAwarePaginator<int, Supplier>
     */
    public function paginate(array $filters = [], int $perPage = 25): LengthAwarePaginator
    {
        return $this->build($filters)->withCount('priceItems')->orderBy('company_name')->paginate($perPage);
    }

    /**
     * @param  array{search?: ?string, is_active?: ?bool}  $filters
     * @return Builder<Supplier>
     */
    public function build(array $filters): Builder
    {
        $query = Supplier::query();

        if (! empty($filters['search'])) {
            $term = '%'.$filters['search'].'%';
            $query->where(fn (Builder $q) => $q->where('company_name', 'like', $term)
                ->orWhere('contact_name', 'like', $term)
                ->orWhere('email', 'like', $term)
                ->orWhere('tax_id', 'like', $term));
        }

        if (array_key_exists('is_active', $filters) && $filters['is_active'] !== null) {
            $query->where('is_active', $filters['is_active']);
        }

        return $query;
    }
}
