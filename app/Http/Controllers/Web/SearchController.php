<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Authorization\MembershipContext;
use App\Authorization\TenantModules;
use App\DataTransferObjects\SearchResultsData;
use App\Http\Controllers\Controller;
use App\Queries\GlobalSearchQuery;
use App\Tenancy\Contracts\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The header's global search (Figma 389:1679). Returns JSON rather than an
 * Inertia page — the dropdown fetches this itself so a keystroke never
 * triggers a page visit.
 *
 * Each category is gated by both the member's capability and the tenant's
 * plan modules, and simply omitted — never a 403 — when either is missing, so
 * a member who can see orders and customers but not inventory still gets
 * partial results. Matches how OrderController::index withholds its pipeline
 * card rather than failing the whole request.
 */
class SearchController extends Controller
{
    public function __construct(
        private readonly MembershipContext $membership,
        private readonly TenantContext $tenants,
    ) {}

    public function __invoke(Request $request, GlobalSearchQuery $query): JsonResponse
    {
        $term = trim((string) $request->query('q', ''));

        if (mb_strlen($term) < 2) {
            return response()->json((new SearchResultsData)->toArray());
        }

        $modules = TenantModules::keysFor($this->tenants->current());

        $categories = array_values(array_filter([
            $this->allowed('orders', 'orders.view', $modules) ? 'orders' : null,
            $this->allowed('customers', 'customers.view', $modules) ? 'customers' : null,
            $this->allowed('inventory', 'inventory.view', $modules) ? 'inventory' : null,
        ]));

        return response()->json($query->search($term, $categories)->toArray());
    }

    /** @param  list<string>  $modules */
    private function allowed(string $module, string $capability, array $modules): bool
    {
        return in_array($module, $modules, true) && $this->membership->can($capability);
    }
}
