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
     * @param  array{status?: ?string, search?: ?string, exclude_bottled?: ?bool}  $filters
     * @return LengthAwarePaginator<int, WineLot>
     */
    public function paginate(array $filters = [], int $perPage = 25): LengthAwarePaginator
    {
        return $this->build($filters)
            ->with(['grapes'])
            ->orderByDesc('created_at')
            ->paginate($perPage);
    }

    /**
     * @param  array{status?: ?string, search?: ?string, exclude_bottled?: ?bool}  $filters
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
                $q->where('lot_number', 'like', $term)
                    ->orWhere('name', 'like', $term)
                    ->orWhere('grape_variety', 'like', $term);
            });
        }

        return $query;
    }
}
