<?php

declare(strict_types=1);

namespace App\Queries;

use App\Enums\TaskStatus;
use App\Models\WorkOrder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

class ListWorkOrdersQuery
{
    /**
     * @param  array{status?: ?string, assignee_id?: ?string, search?: ?string, due_from?: ?string, due_to?: ?string}  $filters
     * @return Collection<int, WorkOrder>
     */
    public function get(array $filters = []): Collection
    {
        $query = WorkOrder::query()->with('assignee');

        if (! empty($filters['status'])) {
            $query->where('status', TaskStatus::from($filters['status']));
        }

        if (! empty($filters['assignee_id'])) {
            $query->where('assignee_id', $filters['assignee_id']);
        }

        if (! empty($filters['search'])) {
            $term = '%'.$filters['search'].'%';
            $query->where(fn (Builder $q) => $q->where('title', 'like', $term)->orWhere('description', 'like', $term));
        }

        if (! empty($filters['due_from'])) {
            $query->where('due_date', '>=', $filters['due_from']);
        }

        if (! empty($filters['due_to'])) {
            $query->where('due_date', '<=', $filters['due_to']);
        }

        return $query->orderBy('sort_order')->orderBy('due_date')->get();
    }
}
