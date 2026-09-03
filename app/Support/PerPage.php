<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Http\Request;

/**
 * Reads how many rows a list page should show.
 *
 * Every list screen's own Query already accepts a `$perPage` argument (see
 * ListCustomersQuery, ListOrdersQuery, ListInventoryItemsQuery and friends) —
 * nothing was reading one off the request, so the page size was fixed at
 * whatever default each controller happened to pass and the "Rows per page"
 * control the design draws (`230:3000`) had nothing to write to.
 *
 * The allow-list is what makes this safe to expose: `?per_page=100000` falls
 * back to `$default` instead of forcing a whole-table scan, and a stale
 * bookmark or a hand-edited URL degrades to the default rather than 500ing.
 */
final class PerPage
{
    /** @var list<int> */
    public const OPTIONS = [10, 25, 50, 100];

    public static function fromRequest(Request $request, int $default = 25): int
    {
        $value = (int) $request->query('per_page', $default);

        return in_array($value, self::OPTIONS, true) ? $value : $default;
    }
}
