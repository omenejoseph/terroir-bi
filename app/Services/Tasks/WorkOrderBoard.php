<?php

declare(strict_types=1);

namespace App\Services\Tasks;

use App\DataTransferObjects\WorkOrderData;
use App\Enums\TaskStatus;
use App\Enums\WorkOrderCategory;
use App\Models\WorkOrder;
use App\Queries\ListWorkOrdersQuery;
use Illuminate\Support\Carbon;

/**
 * The Work Orders board (Figma 267:1781): tasks grouped into columns, plus the
 * board picker above them.
 *
 * A note on what a "board" is here. The design shows named boards — "Cellar
 * Operations", "Vineyard & Maintenance", "Events & Hospitality" — with a
 * favourite star and a "New Board" button. This domain has no board entity: a
 * work order carries a `category` and nothing else that groups it. Rather than
 * invent a boards table from a picture, the picker lists the CATEGORIES that
 * have work, which gives the same interaction (pick one, the columns narrow)
 * over data that exists. The gap is recorded in docs/design/README.md.
 *
 * Likewise the columns. The design draws four lists — This Week, To Do, In
 * Progress, Done — but `TaskStatus` has three, and "This Week" is a date
 * window rather than a state. Moving a card into it would have to mean
 * "reschedule", not "change status", so it is not offered as a column; the
 * board's "Due soon" filter is the honest version of the same question.
 */
class WorkOrderBoard
{
    public function __construct(private readonly ListWorkOrdersQuery $query) {}

    /**
     * @param  array{status?: ?string, assignee_id?: ?string, category?: ?string, search?: ?string, due_from?: ?string, due_to?: ?string}  $filters
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
     *
     * @param  array<string, mixed>  $filters
     * @return list<array{key: string, label: string, count: int}>
     */
    public function boards(array $filters): array
    {
        unset($filters['category']);

        $counts = $this->query->get($filters)
            ->groupBy(fn (WorkOrder $task): string => $task->category?->value ?? 'UNCATEGORISED')
            ->map(fn ($group): int => $group->count());

        $boards = array_values(array_filter(array_map(
            static fn (WorkOrderCategory $category): ?array => ($counts[$category->value] ?? 0) > 0
                ? [
                    'key' => $category->value,
                    'label' => self::categoryLabel($category),
                    'count' => $counts[$category->value],
                ]
                : null,
            WorkOrderCategory::cases(),
        )));

        // Work with no category still has to be reachable, or it is invisible.
        if (($counts['UNCATEGORISED'] ?? 0) > 0) {
            $boards[] = ['key' => 'none', 'label' => 'Uncategorised', 'count' => $counts['UNCATEGORISED']];
        }

        return $boards;
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

    public static function categoryLabel(WorkOrderCategory $category): string
    {
        return match ($category) {
            WorkOrderCategory::Cellar => 'Cellar',
            WorkOrderCategory::Vineyard => 'Vineyard',
            WorkOrderCategory::Maintenance => 'Maintenance',
            WorkOrderCategory::Admin => 'Admin',
            WorkOrderCategory::Delivery => 'Delivery',
            WorkOrderCategory::Event => 'Events',
            WorkOrderCategory::Other => 'Other',
        };
    }
}
