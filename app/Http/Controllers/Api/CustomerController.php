<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\Customers\CreateCustomerAction;
use App\Actions\Customers\OrderTokenAction;
use App\Actions\Customers\UpdateCustomerAction;
use App\DataTransferObjects\CustomerData;
use App\Http\Controllers\Controller;
use App\Http\Requests\Customers\MergeCustomersRequest;
use App\Http\Requests\Customers\QuickCustomerRequest;
use App\Http\Requests\Customers\StoreCustomerRequest;
use App\Http\Requests\Customers\UpdateCustomerRequest;
use App\Models\Customer;
use App\Queries\CustomerAnalyticsQuery;
use App\Queries\CustomerInsightsQuery;
use App\Queries\CustomerOrderAnalyticsQuery;
use App\Queries\ListCustomersQuery;
use App\Queries\ReorderRadarQuery;
use App\Services\Customers\CustomerMergeService;
use App\Services\Customers\LookupCompanyByVatService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CustomerController extends Controller
{
    public function insights(Customer $customer, CustomerInsightsQuery $query): JsonResponse
    {
        return response()->json(['data' => $query->get($customer)]);
    }

    /** Forward-looking order analytics (period revenue, YoY, projections, expected next order). */
    public function orderAnalytics(Customer $customer, CustomerOrderAnalyticsQuery $query): JsonResponse
    {
        return response()->json(['data' => $query->get($customer)]);
    }

    /** Customers overdue to reorder, ranked by value-weighted urgency. */
    public function reorderRadar(ReorderRadarQuery $query): JsonResponse
    {
        return response()->json(['data' => $query->get()]);
    }

    /** Tenant-wide customer analytics: headline totals + per-customer table. */
    public function analytics(CustomerAnalyticsQuery $query): JsonResponse
    {
        return response()->json(['data' => $query->get()]);
    }

    /** Flag/unflag a customer as contacted (mutes it on the radar until its next order). */
    public function markContacted(Request $request, Customer $customer): JsonResponse
    {
        $contacted = $request->boolean('contacted', true);
        $customer->reorder_contacted_at = $contacted ? now() : null;
        $customer->save();

        return response()->json(['data' => CustomerData::fromModel($customer)->toArray()]);
    }

    public function mergePreview(MergeCustomersRequest $request, CustomerMergeService $service): JsonResponse
    {
        $winner = Customer::query()->whereKey((string) $request->validated('winner_id'))->firstOrFail();
        /** @var list<string> $losers */
        $losers = array_values((array) $request->validated('loser_ids'));

        return response()->json(['data' => $service->preview($winner, $losers)]);
    }

    public function merge(MergeCustomersRequest $request, CustomerMergeService $service): JsonResponse
    {
        $winner = Customer::query()->whereKey((string) $request->validated('winner_id'))->firstOrFail();
        /** @var list<string> $losers */
        $losers = array_values((array) $request->validated('loser_ids'));

        return response()->json(['data' => $service->merge($winner, $losers)]);
    }

    /** VIES/OIB lookup to auto-fill name + address on the customer form. */
    public function lookupVat(Request $request, LookupCompanyByVatService $service): JsonResponse
    {
        $vat = trim((string) $request->query('vat', ''));

        if ($vat === '') {
            return response()->json(['message' => 'A vat query parameter is required.'], 422);
        }

        $result = $service->lookup($vat);

        if (isset($result['error'])) {
            return response()->json(['message' => $result['error']], 422);
        }

        return response()->json(['data' => $result]);
    }

    public function index(Request $request, ListCustomersQuery $query): JsonResponse
    {
        $paginator = $query->paginate([
            'search' => $request->query('search'),
            'is_active' => $request->has('is_active') ? $request->boolean('is_active') : null,
            'pricing_tier_id' => $request->query('pricing_tier_id'),
        ]);

        return response()->json([
            'data' => array_map(
                fn (Customer $c) => CustomerData::fromModel($c)->toArray(),
                $paginator->items(),
            ),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }

    public function show(Customer $customer): JsonResponse
    {
        return response()->json(['data' => CustomerData::fromModel($customer->load('pricingTier'))->toArray()]);
    }

    public function store(StoreCustomerRequest $request, CreateCustomerAction $action): JsonResponse
    {
        return response()->json(['data' => $action->execute($request->validated())->toArray()], 201);
    }

    public function quickStore(QuickCustomerRequest $request, CreateCustomerAction $action): JsonResponse
    {
        return response()->json(['data' => $action->execute($request->validated())->toArray()], 201);
    }

    public function update(UpdateCustomerRequest $request, Customer $customer, UpdateCustomerAction $action): JsonResponse
    {
        return response()->json(['data' => $action->execute($customer, $request->validated())->toArray()]);
    }

    public function destroy(Customer $customer): JsonResponse
    {
        // Soft-delete (deactivate) when the customer has orders; otherwise hard delete.
        if ($customer->orders()->exists()) {
            $customer->is_active = false;
            $customer->save();

            return response()->json([
                'data' => CustomerData::fromModel($customer)->toArray(),
                'message' => 'Customer has orders and was deactivated instead of deleted.',
            ]);
        }

        $customer->delete();

        return response()->json(status: 204);
    }

    /** The customer's current self-service order token (admins with customers.tokens). */
    public function showToken(Customer $customer): JsonResponse
    {
        return response()->json(['data' => ['order_token' => $customer->order_token]]);
    }

    public function generateToken(Customer $customer, OrderTokenAction $action): JsonResponse
    {
        $result = $action->generate($customer);

        return response()->json([
            'data' => $result['customer']->toArray() + ['order_token' => $result['token']],
        ]);
    }

    public function revokeToken(Customer $customer, OrderTokenAction $action): JsonResponse
    {
        return response()->json(['data' => $action->revoke($customer)->toArray()]);
    }
}
