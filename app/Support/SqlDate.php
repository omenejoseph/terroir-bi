<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * Driver-portable SQL date expressions, for the drivers this app runs on:
 * sqlite (local dev, and the default for a bare `php artisan test`), MySQL
 * (production, and CI's own dedicated MySQL run via `composer check` — see
 * .github/workflows/ci.yml) and Postgres (the legacy-import test tenant, and
 * any Postgres deployment). The sqlite and MySQL branches are exercised by CI;
 * the Postgres one is covered by SqlDateTest (expression shape) and by running
 * the dashboard against a Postgres database. Month-bucketing elsewhere in this
 * codebase stays in PHP rather than reaching for this.
 */
final class SqlDate
{
    /**
     * A "YYYY-MM" bucket expression for GROUP BY/SELECT — pass a literal SQL
     * column reference, not a value or anything derived from user input;
     * this returns raw SQL, not a binding.
     *
     * @param  literal-string  $column
     * @return literal-string
     */
    public static function month(string $column): string
    {
        return match (DB::connection()->getDriverName()) {
            'sqlite' => "strftime('%Y-%m', {$column})",
            'pgsql' => "to_char({$column}, 'YYYY-MM')",
            default => "DATE_FORMAT({$column}, '%Y-%m')",
        };
    }
}
