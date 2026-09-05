<?php

declare(strict_types=1);

namespace App\Actions\Tasks;

use App\Enums\TaskStatus;
use App\Models\WorkOrder;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\Auth;

class UpdateWorkOrderStatusAction
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function execute(WorkOrder $task, TaskStatus $status): WorkOrder
    {
        $from = $task->status;

        $task->status = $status;
        $task->completed_at = $status === TaskStatus::Done ? ($task->completed_at ?? now()) : null;
        $task->save();

        // WorkOrder's own Auditable listener ignores `status` (see its
        // auditIgnoreOnUpdate()) so this is the only entry for the change.
        $this->audit->record(Auth::user(), 'work_order.status_changed', $task, [
            'from' => $from->value,
            'to' => $status->value,
        ]);

        return $task;
    }
}
