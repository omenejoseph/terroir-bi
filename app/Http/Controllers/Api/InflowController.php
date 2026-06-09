<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\Finance\CreateInflowAction;
use App\Actions\Finance\UpdateInflowAction;
use App\Actions\Finance\UpdateInflowStatusAction;
use App\DataTransferObjects\InflowData;
use App\Enums\InflowStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Finance\StoreInflowRequest;
use App\Http\Requests\Finance\UpdateInflowRequest;
use App\Http\Requests\Finance\UpdateInflowStatusRequest;
use App\Models\Inflow;
use App\Models\User;
use App\Queries\ArAgingQuery;
use App\Queries\ListInflowsQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class InflowController extends Controller
{
    public function index(Request $request, ListInflowsQuery $query): JsonResponse
    {
        $paginator = $query->paginate([
            'status' => $request->query('status'),
            'customer_id' => $request->query('customer_id'),
            'order_id' => $request->query('order_id'),
            'search' => $request->query('search'),
        ]);

        return response()->json([
            'data' => array_map(fn (Inflow $i) => InflowData::fromModel($i)->toArray(), $paginator->items()),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }

    /** Accounts-receivable aging over outstanding order balances. */
    public function aging(ArAgingQuery $query): JsonResponse
    {
        return response()->json(['data' => $query->get()]);
    }

    public function show(Inflow $inflow): JsonResponse
    {
        return response()->json(['data' => InflowData::fromModel($inflow)->toArray()]);
    }

    public function store(StoreInflowRequest $request, CreateInflowAction $action): JsonResponse
    {
        $inflow = $action->execute($request->validated(), $this->userId($request));

        return response()->json(['data' => InflowData::fromModel($inflow)->toArray()], 201);
    }

    public function update(UpdateInflowRequest $request, Inflow $inflow, UpdateInflowAction $action): JsonResponse
    {
        $inflow = $action->execute($inflow, $request->validated());

        return response()->json(['data' => InflowData::fromModel($inflow)->toArray()]);
    }

    public function updateStatus(UpdateInflowStatusRequest $request, Inflow $inflow, UpdateInflowStatusAction $action): JsonResponse
    {
        $inflow = $action->execute($inflow, InflowStatus::from((string) $request->validated('status')));

        return response()->json(['data' => InflowData::fromModel($inflow)->toArray()]);
    }

    public function destroy(Inflow $inflow): JsonResponse
    {
        $inflow->delete();

        return response()->json(status: 204);
    }

    private function userId(Request $request): string
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        return $user->getKey();
    }
}
