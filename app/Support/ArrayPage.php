<?php

declare(strict_types=1);

namespace App\Support;

/**
 * One page of rows that had to be built in PHP (a union of several tables, say) rather than read
 * with a paginated query, in the `{data, meta}` envelope the Pagination component takes. An
 * out-of-range page is clamped to the last one instead of coming back empty.
 */
final class ArrayPage
{
    /**
     * @template T
     *
     * @param  list<T>  $rows
     * @return array{data: list<T>, meta: array{current_page: int, last_page: int, per_page: int, total: int}}
     */
    public static function of(array $rows, int $perPage, int $page): array
    {
        $total = count($rows);
        $last = max(1, (int) ceil($total / max(1, $perPage)));
        $page = min(max(1, $page), $last);

        return [
            'data' => array_values(array_slice($rows, ($page - 1) * $perPage, $perPage)),
            'meta' => ['current_page' => $page, 'last_page' => $last, 'per_page' => $perPage, 'total' => $total],
        ];
    }
}
