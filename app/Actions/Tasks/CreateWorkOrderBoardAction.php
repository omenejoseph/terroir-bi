<?php

declare(strict_types=1);

namespace App\Actions\Tasks;

use App\Models\WorkOrderBoard;

/** Creates a named board (Figma 267:1781's "+ New Board"). */
class CreateWorkOrderBoardAction
{
    public function execute(string $name, string $createdById): WorkOrderBoard
    {
        $sortOrder = ((int) WorkOrderBoard::query()->max('sort_order')) + 1;

        return WorkOrderBoard::create([
            'name' => $name,
            'created_by_id' => $createdById,
            'sort_order' => $sortOrder,
        ]);
    }
}
