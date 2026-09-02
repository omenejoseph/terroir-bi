<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Http\Request;

/**
 * Reads the inventory list filters off a request.
 *
 * Both the API and the Inertia web controller accept the same query string, so
 * a saved/shared URL means the same thing on either. Parsing it once also keeps
 * the "absent vs. explicitly false" distinction (`is_active`, `is_for_sale`)
 * from being re-implemented differently in each controller.
 */
final class InventoryItemFilters
{
    /**
     * @return array{search: ?string, category: ?string, is_active: ?bool, is_for_sale: ?bool, sellable: bool}
     */
    public static function fromRequest(Request $request): array
    {
        $search = $request->query('search');
        $category = $request->query('category');

        return [
            'search' => is_string($search) && $search !== '' ? $search : null,
            'category' => is_string($category) && $category !== '' ? $category : null,
            'is_active' => $request->has('is_active') ? $request->boolean('is_active') : null,
            'is_for_sale' => $request->has('is_for_sale') ? $request->boolean('is_for_sale') : null,
            'sellable' => $request->boolean('sellable'),
        ];
    }
}
