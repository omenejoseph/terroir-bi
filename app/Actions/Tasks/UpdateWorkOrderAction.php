<?php

declare(strict_types=1);

namespace App\Actions\Tasks;

use App\Models\WorkOrder;

class UpdateWorkOrderAction
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function execute(WorkOrder $task, array $attributes): WorkOrder
    {
        $task->fill($attributes)->save();

        return $task;
    }
}
