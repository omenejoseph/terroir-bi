<?php

declare(strict_types=1);

namespace App\Actions\Tasks;

use App\Enums\TaskStatus;
use App\Models\WorkOrder;

class CreateWorkOrderAction
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function execute(array $attributes, string $createdById): WorkOrder
    {
        $attributes['created_by_id'] = $createdById;
        $task = WorkOrder::create($attributes);

        if ($task->status === TaskStatus::Done && $task->completed_at === null) {
            $task->completed_at = now();
            $task->save();
        }

        return $task;
    }
}
