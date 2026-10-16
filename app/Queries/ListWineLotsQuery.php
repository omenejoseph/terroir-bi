<?php

declare(strict_types=1);

namespace App\Queries;

use App\Enums\WineLotStatus;
use App\Models\WineLot;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

/** Filterable wine-lot listing. */
class ListWineLotsQuery
{
    /**
     * `with_counts` adds the bottled-archive figures: counts of additions,
     * analyses and bottlings, and the latest bottling with its item.
     *
     * @param  array{status?: ?string, search?: ?string, exclude_bottled?: ?bool, with_counts?: ?bool}  $filters
     * @return LengthAwarePaginator<int, WineLot>
     */
    public function paginate(array $filters = [], int $perPage = 25): LengthAwarePaginator
    {
        return $this->build($filters)
            ->with(['grapes', 'latestAnalysis', 'latestAddition'])
            ->when(! empty($filters['with_counts']), fn (Builder $q) => $q
                ->withCount(['additions', 'analyses', 'bottlings'])
                ->with('latestBottling.inventoryItem'))
            ->orderByDesc('created_at')
            ->paginate($perPage);
    }

    /**
     * @param  array{status?: ?string, search?: ?string, exclude_bottled?: ?bool, with_counts?: ?bool}  $filters
     * @return Builder<WineLot>
     */
    public function build(array $filters): Builder
    {
        $query = WineLot::query();

        if (! empty($filters['status'])) {
            $query->where('status', WineLotStatus::from($filters['status']));
        }

        if (! empty($filters['exclude_bottled'])) {
            $query->where('status', '!=', WineLotStatus::Bottled);
        }

        if (! empty($filters['search'])) {
            $term = '%'.$filters['search'].'%';
            $query->where(function (Builder $q) use ($term): void {
                $q->whereLike('lot_number', $term)
                    ->orWhereLike('name', $term)
                    ->orWhereLike('grape_variety', $term);
            });
        }

        return $query;
    }
}
