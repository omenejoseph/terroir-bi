<?php

declare(strict_types=1);

namespace App\Queries;

use App\Enums\CostStatus;
use App\Models\Cost;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

class ListCostsQuery
{
    /** Reserved categories that drive the All / Invoices / Payments / Others tabs. */
    public const INVOICE_CATEGORY = 'Invoice';

    public const PAYMENT_CATEGORY = 'Payment';

    /**
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, Cost>
     */
    public function paginate(array $filters = [], int $perPage = 25): LengthAwarePaginator
    {
        return $this->build($filters)->with('supplier')->orderByDesc('date')->paginate($perPage);
    }

    /**
     * @param  array<string, mixed>  $filters
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
            $query->where('status', CostStatus::from((string) $filters['status']));
        }

        if (! empty($filters['supplier_id'])) {
            $query->where('supplier_id', $filters['supplier_id']);
        }

        $this->applyGroup($query, isset($filters['group']) ? (string) $filters['group'] : null);

        if (! empty($filters['date_from'])) {
            $query->whereDate('date', '>=', $filters['date_from']);
        }
        if (! empty($filters['date_to'])) {
            $query->whereDate('date', '<=', $filters['date_to']);
        }

        return $query;
    }

    /**
     * Tab group: Invoices / Payments are the reserved categories; Others is the rest.
     *
     * @param  Builder<Cost>  $query
     */
    private function applyGroup(Builder $query, ?string $group): void
    {
        match ($group) {
            'invoices' => $query->where('category', self::INVOICE_CATEGORY),
            'payments' => $query->where('category', self::PAYMENT_CATEGORY),
            'others' => $query->whereNotIn('category', [self::INVOICE_CATEGORY, self::PAYMENT_CATEGORY]),
            default => null,
        };
    }
}
