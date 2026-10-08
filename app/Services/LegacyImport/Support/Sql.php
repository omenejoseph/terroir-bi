<?php

declare(strict_types=1);

namespace App\Services\LegacyImport\Support;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

final class Sql
{
    /**
     * How many rows a GROUP BY ... HAVING query returns. `$query->count()` would count the
     * first group's rows instead, so the grouped query is wrapped as a subquery.
     */
    public static function countGroups(Builder $query): int
    {
        return DB::query()->fromSub($query, 'grouped')->count();
    }
}
