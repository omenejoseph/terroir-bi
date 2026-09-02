<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Actions\Tasks\CreateWorkOrderAction;
use App\Actions\Tasks\ReorderWorkOrdersAction;
use App\Actions\Tasks\UpdateWorkOrderAction;
use App\Actions\Tasks\UpdateWorkOrderStatusAction;
use App\Enums\TaskStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Tasks\ReorderTasksRequest;
use App\Http\Requests\Tasks\StoreWorkOrderRequest;
use App\Http\Requests\Tasks\UpdateTaskStatusRequest;
use App\Http\Requests\Tasks\UpdateWorkOrderRequest;
use App\Models\Membership;
use App\Models\User;
use App\Models\Vessel;
use App\Models\WorkOrder;
use App\Services\Tasks\WorkOrderBoard;
use App\Support\WorkOrderFilters;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Inertia counterpart of Api\WorkOrderController.
 *
 * Reads go through the same ListWorkOrdersQuery (via WorkOrderBoard, which only
 * groups what that query returns) and writes through the same Actions as the
 * API, so the two transports share behaviour by construction.
 *
 * These routes carry no `can:` gate, matching routes/api.php: task planning is
 * open to any member of the tenant. That is the API's stance and the web app
 * must not quietly be stricter or looser than it.
 */
class WorkOrderController extends Controller
{
    /** The board (Figma 267:1781). */
    public function index(Request $request, WorkOrderBoard $board): Response
    {
        $filters = WorkOrderFilters::fromRequest($request);
        $scoped = $this->scoped($filters, $request);

        return Inertia::render('WorkOrders/Index', [
            'board' => $board->columns($scoped),
            // The picker's counts deliberately ignore the chosen board.
            'boards' => $board->boards($scoped),
            'filters' => $filters,
            // The task drawer's two pickers, only paid for when it opens.
            'assigneeOptions' => Inertia::optional(fn (): array => $this->assignees()),
            'vesselOptions' => Inertia::optional(fn (): array => $this->vessels()),
        ]);
    }

    public function store(StoreWorkOrderRequest $request, CreateWorkOrderAction $action): RedirectResponse
    {
        $action->execute($request->validated(), $this->userId($request));

        return back()->with('success', __('Task created.'));
    }

    public function update(
        UpdateWorkOrderRequest $request,
        WorkOrder $workOrder,
        UpdateWorkOrderAction $action,
    ): RedirectResponse {
        $action->execute($workOrder, $request->validated());

        return back()->with('success', __('Task updated.'));
    }

    /**
     * Dragging a card to another column. Status carries the completion side
     * effects (`completed_at`), so this goes through the status action rather
     * than a bare update — dropping a card on Done must mean the same thing as
     * ticking it.
     */
    public function updateStatus(
        UpdateTaskStatusRequest $request,
        WorkOrder $workOrder,
        UpdateWorkOrderStatusAction $action,
    ): RedirectResponse {
        $action->execute($workOrder, TaskStatus::from((string) $request->validated('status')));

        return back();
    }

    /** Dragging a card within a column. */
    public function reorder(ReorderTasksRequest $request, ReorderWorkOrdersAction $action): RedirectResponse
    {
        /** @var list<string> $ids */
        $ids = array_values((array) $request->validated('ids'));
        $action->execute($ids);

        return back();
    }

    public function destroy(WorkOrder $workOrder): RedirectResponse
    {
        $workOrder->delete();

        return back()->with('success', __('Task deleted.'));
    }

    /**
     * The filters the query understands, plus the two the board expresses as
     * toggles: "Due soon" is a date ceiling and "My Tasks" is an assignee, so
     * both are resolved here rather than being extra query parameters.
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    private function scoped(array $filters, Request $request): array
    {
        $scoped = $filters;

        if ($filters['due_soon'] === true) {
            $scoped['due_to'] = WorkOrderBoard::dueSoonWindow();
        }

        if ($filters['mine'] === true) {
            $scoped['assignee_id'] = $this->userId($request);
        }

        // 'none' is the uncategorised board; the query filters on a real value,
        // so it cannot express "has no category" and the board does it instead.
        if ($scoped['category'] === 'none') {
            $scoped['category'] = null;
            $scoped['uncategorised'] = true;
        }

        unset($scoped['due_soon'], $scoped['mine']);

        return $scoped;
    }

    /**
     * Members of this tenant, for the assignee picker.
     *
     * @return list<array{value: string, label: string}>
     */
    private function assignees(): array
    {
        return Membership::query()
            ->with('user')
            ->get()
            ->map(fn (Membership $membership): ?array => $membership->user instanceof User
                ? [
                    'value' => $membership->user->getKey(),
                    'label' => trim($membership->user->first_name.' '.$membership->user->last_name),
                ]
                : null)
            ->filter()
            ->sortBy('label')
            ->values()
            ->all();
    }

    /**
     * @return list<array{value: string, label: string, description: ?string}>
     */
    private function vessels(): array
    {
        return Vessel::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'type'])
            ->map(fn (Vessel $vessel): array => [
                'value' => $vessel->getKey(),
                'label' => $vessel->name,
                'description' => $vessel->type,
            ])
            ->all();
    }

    private function userId(Request $request): string
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        return $user->getKey();
    }
}
