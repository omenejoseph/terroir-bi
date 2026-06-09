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
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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

    public function stats(): JsonResponse
    {
        return response()->json(['data' => [
            'todo' => WorkOrder::query()->where('status', TaskStatus::Todo)->count(),
            'in_progress' => WorkOrder::query()->where('status', TaskStatus::InProgress)->count(),
            'done' => WorkOrder::query()->where('status', TaskStatus::Done)->count(),
            'overdue' => WorkOrder::query()
                ->where('status', '!=', TaskStatus::Done)
                ->whereNotNull('due_date')
                ->where('due_date', '<', now())
                ->count(),
        ]]);
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
