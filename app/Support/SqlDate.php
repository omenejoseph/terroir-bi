<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * Driver-portable SQL date expressions, for the two drivers this app
 * actually runs on: sqlite (local dev, and the default for a bare
 * `php artisan test`) and MySQL (production, and CI's own dedicated MySQL
 * run via `composer check` — see .github/workflows/ci.yml). Both branches
 * below are genuinely exercised by CI, unlike a one-off driver check with
 * no test coverage for the untaken branch, which is why month-bucketing
 * elsewhere in this codebase stays in PHP rather than reaching for this.
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
            default => "DATE_FORMAT({$column}, '%Y-%m')",
        };
    }
}
