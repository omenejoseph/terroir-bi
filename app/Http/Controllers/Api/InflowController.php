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
use App\Models\InflowChange;
use App\Models\User;
use App\Queries\ArAgingQuery;
use App\Queries\InflowAnalyticsQuery;
use App\Queries\ListInflowsQuery;
use App\Support\Period;
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

    /** Cash-in analytics (invoiced / collected / pending, cash flow, by category/customer). */
    public function analytics(Request $request, InflowAnalyticsQuery $query): JsonResponse
    {
        [$from, $to] = Period::resolve(
            $request->query('period') !== null ? (string) $request->query('period') : null,
            $request->query('from') !== null ? (string) $request->query('from') : null,
            $request->query('to') !== null ? (string) $request->query('to') : null,
        );

        return response()->json(['data' => $query->get($from, $to)]);
    }

    public function show(Inflow $inflow): JsonResponse
    {
        return response()->json(['data' => InflowData::fromModel($this->hydrate($inflow))->toArray()]);
    }

    /** Edit history (newest first) for an inflow. */
    public function changes(Inflow $inflow): JsonResponse
    {
        $changes = $inflow->changes()->with('changedBy')->orderByDesc('created_at')->get()
            ->map(fn (InflowChange $c): array => [
                'id' => $c->getKey(),
                'changes' => $c->changes,
                'changed_by' => $c->changedBy?->fullName(),
                'created_at' => $c->created_at?->toIso8601String(),
            ])->all();

        return response()->json(['data' => $changes]);
    }

    public function store(StoreInflowRequest $request, CreateInflowAction $action): JsonResponse
    {
        $inflow = $action->execute($request->validated(), $this->userId($request));

        return response()->json(['data' => InflowData::fromModel($this->hydrate($inflow))->toArray()], 201);
    }

    public function update(UpdateInflowRequest $request, Inflow $inflow, UpdateInflowAction $action): JsonResponse
    {
        $inflow = $action->execute($inflow, $request->validated());

        return response()->json(['data' => InflowData::fromModel($this->hydrate($inflow))->toArray()]);
    }

    /** Load the order + change count so the DTO can expose order_number / changes_count. */
    private function hydrate(Inflow $inflow): Inflow
    {
        return $inflow->load('order')->loadCount('changes');
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
