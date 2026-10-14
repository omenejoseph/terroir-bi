<?php

declare(strict_types=1);

namespace App\Actions\Cellar\Activities;

use App\Actions\Tasks\UpdateWorkOrderStatusAction;
use App\Enums\CellarActivityStatus;
use App\Enums\TaskStatus;
use App\Models\CellarActivityRound;
use App\Models\WorkOrder;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Cancel a round's remaining plan. Planned items are removed. If nothing in
 * the round was ever done or skipped, the round and its board card go too;
 * otherwise the round stays as history and its card is closed.
 */
class DeleteCellarRoundAction
{
    public function __construct(
        private readonly UpdateWorkOrderStatusAction $status,
        private readonly AuditLogger $audit,
    ) {}

    /** @return array{removed: int, kept_history: bool} */
    public function execute(CellarActivityRound $round): array
    {
        return DB::transaction(function () use ($round): array {
            $planned = $round->activities()->where('status', CellarActivityStatus::Planned->value)->get();
            $hasHistory = $round->activities()->where('status', '!=', CellarActivityStatus::Planned->value)->exists();

            foreach ($planned as $activity) {
                $activity->delete();
            }

            $card = $round->work_order_id !== null
                ? WorkOrder::query()->whereKey($round->work_order_id)->first()
                : null;

            $this->audit->record(Auth::user(), 'cellar_activity_round.deleted', $round, [
                'name' => $round->name,
                'removed' => $planned->count(),
                'kept_history' => $hasHistory,
            ]);

            if ($hasHistory) {
                if ($card !== null && ! $card->status->isClosed()) {
                    $this->status->execute($card, TaskStatus::Done);
                }
            } else {
                $round->delete();
                $card?->delete();
            }

            return ['removed' => $planned->count(), 'kept_history' => $hasHistory];
        });
    }
}
