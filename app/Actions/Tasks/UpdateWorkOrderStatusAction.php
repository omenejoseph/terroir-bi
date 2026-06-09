<?php

declare(strict_types=1);

namespace App\Actions\Tasks;

use App\Enums\TaskStatus;
use App\Models\WorkOrder;

class UpdateWorkOrderStatusAction
{
    public function execute(WorkOrder $task, TaskStatus $status): WorkOrder
    {
        $task->status = $status;
        $task->completed_at = $status === TaskStatus::Done ? ($task->completed_at ?? now()) : null;
        $task->save();

        return $task;
    }
}
