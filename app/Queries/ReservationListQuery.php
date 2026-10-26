<?php

declare(strict_types=1);

namespace App\Queries;

use App\Enums\ReservationStatus;
use App\Models\Reservation;
use App\Support\ReservationFilters;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

/**
 * The reservation list: soonest first, as the old app sorted it, so the next group to arrive is
 * at the top of the month.
 */
class ReservationListQuery
{
    /**
     * @param  array{search: ?string, status: ?string, program_id: ?string, customer_id: ?string, period: string, from: ?string, to: ?string}  $filters
     * @return LengthAwarePaginator<int, Reservation>
     */
    public function paginate(array $filters, int $perPage = 25): LengthAwarePaginator
    {
        return $this->build($filters)
            ->with(['program', 'customer', 'createdBy'])
            ->orderBy('date')
            ->orderBy('id')
            ->paginate($perPage);
    }

    /**
     * @param  array{search: ?string, status: ?string, program_id: ?string, customer_id: ?string, period: string, from: ?string, to: ?string}  $filters
     * @return Builder<Reservation>
     */
    public function build(array $filters): Builder
    {
        $query = Reservation::query();

        [$from, $to] = ReservationFilters::window($filters);

        if ($from !== null) {
            $query->where('date', '>=', $from);
        }

        if ($to !== null) {
            $query->where('date', '<=', $to);
        }

        $status = $filters['status'] !== null ? ReservationStatus::tryFrom($filters['status']) : null;
        if ($status !== null) {
            $query->where('status', $status);
        }

        if ($filters['program_id'] !== null) {
            $query->where('program_id', $filters['program_id']);
        }

        if ($filters['customer_id'] === 'walk_in') {
            $query->whereNull('customer_id');
        } elseif ($filters['customer_id'] !== null) {
            $query->where('customer_id', $filters['customer_id']);
        }

        if ($filters['search'] !== null) {
            $query->whereLike('guest_name', '%'.$filters['search'].'%');
        }

        return $query;
    }
}
