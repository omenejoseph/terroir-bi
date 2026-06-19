<?php

declare(strict_types=1);

namespace App\Queries;

use App\Models\Customer;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

/**
 * Reusable, filterable listing of customers — shared by the API and any future
 * Livewire/Inertia table.
 */
class ListCustomersQuery
{
    /**
     * @param  array{search?: ?string, is_active?: ?bool, pricing_tier_id?: ?string}  $filters
     * @return LengthAwarePaginator<int, Customer>
     */
    public function paginate(array $filters = [], int $perPage = 25): LengthAwarePaginator
    {
        return $this->build($filters)->orderBy('company_name')->paginate($perPage);
    }

    /**
     * @param  array{search?: ?string, is_active?: ?bool, pricing_tier_id?: ?string}  $filters
     * @return Builder<Customer>
     */
    public function build(array $filters): Builder
    {
        // Order count + revenue per customer for the list card (non-consignment
        // only, matching the analytics queries). Revenue is exposed minor-only;
        // the controller gates it behind financials visibility.
        $query = Customer::query()
            ->with('pricingTier')
            ->withCount(['orders as order_count' => fn (Builder $q) => $q->where('is_consignment', false)])
            ->withSum(
                ['orders as revenue_minor' => fn (Builder $q) => $q->where('is_consignment', false)],
                'total_amount',
            );

        if (! empty($filters['search'])) {
            $term = '%'.$filters['search'].'%';
            $query->where(function (Builder $q) use ($term) {
                $q->where('company_name', 'like', $term)
                    ->orWhere('contact_name', 'like', $term)
                    ->orWhere('email', 'like', $term);
            });
        }

        if (array_key_exists('is_active', $filters) && $filters['is_active'] !== null) {
            $query->where('is_active', $filters['is_active']);
        }

        if (! empty($filters['pricing_tier_id'])) {
            $query->where('pricing_tier_id', $filters['pricing_tier_id']);
        }

        return $query;
    }
}
