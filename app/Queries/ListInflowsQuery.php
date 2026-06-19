<?php

declare(strict_types=1);

namespace App\Queries;

use App\Enums\InflowStatus;
use App\Models\Inflow;
use App\Support\Money\CurrencyRegistry;
use App\Support\Money\Money;
use App\Tenancy\Contracts\TenantContext;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * Filterable listing of money-in records (payments / A/R).
 */
class ListInflowsQuery
{
    public function __construct(private readonly TenantContext $tenant) {}

    /**
     * @param  array{status?: ?string, customer_id?: ?string, order_id?: ?string, search?: ?string, date_from?: ?string, date_to?: ?string}  $filters
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
     * Invoiced / Collected / Pending totals over the whole filtered set (not
     * just the current page), so the index summary bar stays accurate under
     * pagination. Credit notes are excluded from totals, matching the prototype.
     *
     * @param  array{status?: ?string, customer_id?: ?string, order_id?: ?string, search?: ?string, date_from?: ?string, date_to?: ?string}  $filters
     * @return array<string, mixed>
     */
    public function summary(array $filters): array
    {
        $currency = $this->currency();
        $rows = $this->build($filters)->where('is_credit_note', false)
            ->get(['amount', 'status', 'due_date']);

        $sum = fn ($collection): int => (int) $collection->sum(fn (Inflow $i) => $i->amount->getMinorAmount());
        $card = fn ($collection): array => [
            'total' => Money::fromMinor($sum($collection), $currency)->jsonSerialize(),
            'count' => $collection->count(),
        ];

        $received = $rows->where('status', InflowStatus::Received);
        $pending = $rows->where('status', InflowStatus::Pending);
        $today = Carbon::today();
        $overdue = $pending->filter(fn (Inflow $i) => $i->due_date !== null && $i->due_date->lt($today));

        return [
            'invoiced' => $card($rows),
            'collected' => $card($received),
            'pending' => $card($pending) + ['overdue' => $overdue->count()],
        ];
    }

    private function currency(): string
    {
        $currency = $this->tenant->current()?->settings()->first()?->default_currency;

        return $currency ?? CurrencyRegistry::default()->code;
    }

    /**
     * @param  array{status?: ?string, customer_id?: ?string, order_id?: ?string, search?: ?string, date_from?: ?string, date_to?: ?string}  $filters
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

        if (! empty($filters['date_from'])) {
            $query->whereDate('date', '>=', $filters['date_from']);
        }

        if (! empty($filters['date_to'])) {
            $query->whereDate('date', '<=', $filters['date_to']);
        }

        return $query;
    }
}
