<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\Tasks\CreateWorkOrderAction;
use App\Actions\Tasks\ReorderWorkOrdersAction;
use App\Actions\Tasks\UpdateWorkOrderAction;
use App\Actions\Tasks\UpdateWorkOrderStatusAction;
use App\DataTransferObjects\WorkOrderData;
use App\Enums\TaskStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Tasks\ReorderTasksRequest;
use App\Http\Requests\Tasks\StoreWorkOrderRequest;
use App\Http\Requests\Tasks\UpdateTaskStatusRequest;
use App\Http\Requests\Tasks\UpdateWorkOrderRequest;
use App\Models\User;
use App\Models\WorkOrder;
use App\Queries\ListWorkOrdersQuery;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class WorkOrderController extends Controller
{
    public function index(Request $request, ListWorkOrdersQuery $query): JsonResponse
    {
        $tasks = $query->get([
            'status' => $request->query('status'),
            'assignee_id' => $request->query('assignee_id'),
            'search' => $request->query('search'),
            'due_from' => $request->query('due_from'),
            'due_to' => $request->query('due_to'),
        ]);

        return response()->json([
            'data' => $tasks->map(fn (WorkOrder $t) => WorkOrderData::fromModel($t)->toArray())->all(),
        ]);
    }

    public function stats(Request $request): JsonResponse
    {
        $horizon = $this->statsHorizon((string) $request->query('range', 'ALL'));

        // When a range is chosen, scope the counters to work due within the
        // horizon (undated work is excluded). ALL applies no date filter.
        $scoped = function () use ($horizon): Builder {
            $query = WorkOrder::query();
            if ($horizon !== null) {
                $query->whereNotNull('due_date')->where('due_date', '<=', $horizon);
            }

            return $query;
        };

        return response()->json(['data' => [
            'todo' => $scoped()->where('status', TaskStatus::Todo)->count(),
            'in_progress' => $scoped()->where('status', TaskStatus::InProgress)->count(),
            'done' => $scoped()->where('status', TaskStatus::Done)->count(),
            'overdue' => $scoped()
                ->where('status', '!=', TaskStatus::Done)
                ->whereNotNull('due_date')
                ->where('due_date', '<', now())
                ->count(),
        ]]);
    }

    private function statsHorizon(string $range): ?Carbon
    {
        return match ($range) {
            '7D' => now()->addDays(7),
            '30D' => now()->addDays(30),
            '90D' => now()->addDays(90),
            '1Y' => now()->addYear(),
            default => null, // ALL — no date filter
        };
    }

    public function show(WorkOrder $workOrder): JsonResponse
    {
        return response()->json(['data' => WorkOrderData::fromModel($workOrder->load('assignee'))->toArray()]);
    }

    public function store(StoreWorkOrderRequest $request, CreateWorkOrderAction $action): JsonResponse
    {
        $task = $action->execute($request->validated(), $this->userId($request));

        return response()->json(['data' => WorkOrderData::fromModel($task->load('assignee'))->toArray()], 201);
    }

    public function update(UpdateWorkOrderRequest $request, WorkOrder $workOrder, UpdateWorkOrderAction $action): JsonResponse
    {
        $task = $action->execute($workOrder, $request->validated());

        return response()->json(['data' => WorkOrderData::fromModel($task->load('assignee'))->toArray()]);
    }

    public function updateStatus(UpdateTaskStatusRequest $request, WorkOrder $workOrder, UpdateWorkOrderStatusAction $action): JsonResponse
    {
        $task = $action->execute($workOrder, TaskStatus::from((string) $request->validated('status')));

        return response()->json(['data' => WorkOrderData::fromModel($task->load('assignee'))->toArray()]);
    }

    public function reorder(ReorderTasksRequest $request, ReorderWorkOrdersAction $action): JsonResponse
    {
        /** @var list<string> $ids */
        $ids = array_values(array_map('strval', (array) $request->validated('ids')));
        $action->execute($ids);

        return response()->json(status: 204);
    }

    public function destroy(WorkOrder $workOrder): JsonResponse
    {
        $workOrder->delete();

        return response()->json(status: 204);
    }

    private function userId(Request $request): string
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        return $user->getKey();
    }
}
