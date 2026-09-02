<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Http\Request;

/**
 * Reads the customer list filters off a request.
 *
 * The API and the Inertia web controller accept the same query string, so a
 * shared or bookmarked URL means the same thing on either transport. Parsing
 * once also keeps the "absent vs. explicitly false" distinction on `is_active`
 * from being re-implemented differently in each controller.
 */
final class CustomerFilters
{
    /**
     * @return array{search: ?string, is_active: ?bool, pricing_tier_id: ?string, customer_type: ?string}
     */
    public static function fromRequest(Request $request): array
    {
        $search = $request->query('search');
        $tier = $request->query('pricing_tier_id');
        $type = $request->query('customer_type');

        return [
            'search' => is_string($search) && $search !== '' ? $search : null,
            'is_active' => $request->has('is_active') ? $request->boolean('is_active') : null,
            'pricing_tier_id' => is_string($tier) && $tier !== '' ? $tier : null,
            'customer_type' => is_string($type) && $type !== '' ? $type : null,
        ];
    }
}
