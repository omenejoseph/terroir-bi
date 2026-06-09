<?php

declare(strict_types=1);

namespace App\Actions\Tasks;

use App\Models\WorkOrder;
use Illuminate\Support\Facades\DB;

/**
 * Persist a new ordering: sort_order follows the given id sequence. Unknown or
 * cross-tenant ids are ignored (tenant scope on the update).
 */
class ReorderWorkOrdersAction
{
    /**
     * @param  list<string>  $orderedIds
     */
    public function execute(array $orderedIds): void
    {
        DB::transaction(function () use ($orderedIds): void {
            foreach ($orderedIds as $index => $id) {
                WorkOrder::query()->whereKey($id)->update(['sort_order' => $index]);
            }
        });
    }
}
