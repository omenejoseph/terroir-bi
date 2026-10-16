<?php

declare(strict_types=1);

namespace App\Queries;

use App\DataTransferObjects\WorkOrderData;
use App\Models\AuditLog;
use App\Models\WorkOrder;
use App\Tenancy\Contracts\TenantContext;
use Illuminate\Support\Carbon;

/**
 * The Work Orders board's "Recent Activity": tasks added or moved to another column in the last
 * hour, newest first, each with a one-line description of what happened. A task shows once, for its
 * latest event.
 */
class RecentWorkOrderActivityQuery
{
    private const WINDOW_MINUTES = 60;

    public function __construct(private readonly TenantContext $tenant) {}

    /**
     * @return list<array{task: array<string, mixed>, kind: string, from: ?string, to: ?string, at: string}>
     */
    public function get(): array
    {
        $since = Carbon::now()->subMinutes(self::WINDOW_MINUTES);
        $events = [];

        WorkOrder::query()->where('created_at', '>=', $since)->get()->each(function (WorkOrder $task) use (&$events): void {
            $events[$task->getKey()] = ['kind' => 'added', 'from' => null, 'to' => $task->status->value, 'at' => $task->created_at];
        });

        $moves = AuditLog::query()
            ->where('tenant_id', $this->tenant->id())
            ->where('action', 'work_order.status_changed')
            ->where('subject_type', (new WorkOrder)->getMorphClass())
            ->where('created_at', '>=', $since)
            ->orderBy('created_at')
            ->get();

        foreach ($moves as $log) {
            $events[(string) $log->subject_id] = [
                'kind' => 'moved',
                'from' => $log->metadata['from'] ?? null,
                'to' => $log->metadata['to'] ?? null,
                'at' => $log->created_at,
            ];
        }

        if ($events === []) {
            return [];
        }

        return collect(WorkOrder::query()->whereIn('id', array_keys($events))->get())
            ->map(fn (WorkOrder $task): array => [
                'task' => WorkOrderData::fromModel($task)->toArray(),
                'kind' => $events[$task->getKey()]['kind'],
                'from' => $events[$task->getKey()]['from'],
                'to' => $events[$task->getKey()]['to'],
                'at' => $events[$task->getKey()]['at']->toIso8601String(),
            ])
            ->sortByDesc('at')
            ->values()
            ->all();
    }
}
