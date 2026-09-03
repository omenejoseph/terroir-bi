<?php

declare(strict_types=1);

namespace App\Services\Tasks;

use App\DataTransferObjects\WorkOrderData;
use App\Enums\TaskStatus;
use App\Models\WorkOrder;
use App\Models\WorkOrderBoard;
use App\Queries\ListWorkOrdersQuery;
use Illuminate\Support\Carbon;

/**
 * The Work Orders board (Figma 267:1781): tasks grouped into columns, plus
 * the board picker above them — now backed by real, user-created boards
 * (App\Models\WorkOrderBoard), not the category-derived stand-in this class
 * used before one existed. `category` (what kind of work a task is) is a
 * separate axis from `board_id` (which board it's organized under) and is
 * untouched here.
 *
 * Likewise the columns. The design draws four lists — This Week, To Do, In
 * Progress, Done — but `TaskStatus` has three, and "This Week" is a date
 * window rather than a state. Moving a card into it would have to mean
 * "reschedule", not "change status", so it is not offered as a column; the
 * board's "Due soon" filter is the honest version of the same question.
 */
class WorkOrderBoardPresenter
{
    public function __construct(private readonly ListWorkOrdersQuery $query) {}

    /**
     * @param  array{status?: ?string, assignee_id?: ?string, category?: ?string, board_id?: ?string, search?: ?string, due_from?: ?string, due_to?: ?string}  $filters
     * @return array{
     *     columns: list<array{key: string, label: string, count: int, tasks: list<array<string, mixed>>}>,
     *     total: int,
     * }
     */
    public function columns(array $filters): array
    {
        $tasks = $this->query->get($filters);

        $columns = array_map(function (TaskStatus $status) use ($tasks): array {
            $inColumn = $tasks
                ->filter(fn (WorkOrder $task): bool => $task->status === $status)
                ->values();

            return [
                'key' => $status->value,
                'label' => self::statusLabel($status),
                'count' => $inColumn->count(),
                'tasks' => $inColumn
                    ->map(fn (WorkOrder $task): array => WorkOrderData::fromModel($task)->toArray())
                    ->all(),
            ];
        }, TaskStatus::cases());

        return ['columns' => $columns, 'total' => $tasks->count()];
    }

    /**
     * The board picker. Counts ignore the selected board for the same reason
     * the orders status chips ignore the selected status: otherwise choosing
     * one board zeroes the others and you cannot see what you would switch to.
     * Every board shows regardless of count — unlike the old category picker
     * (which hid empty categories), a board someone just created must not
     * vanish from the picker before anything is on it.
     *
     * @param  array<string, mixed>  $filters
     * @return list<array{key: string, label: string, count: int, favorite: bool}>
     */
    public function boards(array $filters, ?string $favoriteBoardId): array
    {
        unset($filters['board_id']);

        $counts = $this->query->get($filters)
            ->groupBy(fn (WorkOrder $task): string => $task->board_id ?? 'NONE')
            ->map(fn ($group): int => $group->count());

        return WorkOrderBoard::query()
            ->orderBy('sort_order')
            ->get()
            ->map(fn (WorkOrderBoard $board): array => [
                'key' => $board->getKey(),
                'label' => $board->name,
                'count' => $counts[$board->getKey()] ?? 0,
                'favorite' => $board->getKey() === $favoriteBoardId,
            ])
            ->all();
    }

    /** "Due soon" means inside the next seven days, including anything overdue. */
    public static function dueSoonWindow(): string
    {
        return Carbon::now()->addDays(7)->endOfDay()->toDateTimeString();
    }

    public static function statusLabel(TaskStatus $status): string
    {
        return match ($status) {
            TaskStatus::Todo => 'To Do',
            TaskStatus::InProgress => 'In Progress',
            TaskStatus::Done => 'Done',
        };
    }
}
