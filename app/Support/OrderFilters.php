<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Http\Request;

/**
 * Reads the order list filters off a request.
 *
 * The API and the Inertia web controller accept the same query string, so a
 * shared or bookmarked URL means the same thing on either transport. Parsing
 * once also keeps the period token (which the pipeline card and the table both
 * key off) from drifting between them.
 */
final class OrderFilters
{
    /**
     * @return array{status: ?string, search: ?string, customer_id: ?string, period: ?string}
     */
    public static function fromRequest(Request $request): array
    {
        return [
            'status' => self::str($request->query('status')),
            'search' => self::str($request->query('search')),
            'customer_id' => self::str($request->query('customer_id')),
            'period' => self::str($request->query('period')),
        ];
    }

    private static function str(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
