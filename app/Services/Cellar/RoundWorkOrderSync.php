<?php

declare(strict_types=1);

namespace App\Services\Cellar;

use App\Actions\Tasks\UpdateWorkOrderStatusAction;
use App\Enums\CellarActivityStatus;
use App\Enums\TaskStatus;
use App\Models\CellarActivityRound;
use App\Models\WorkOrder;
use Illuminate\Support\Carbon;

/**
 * Keeps a round's board card in step with its activities: the card's due date
 * follows the earliest open item, and the card is closed once nothing in the
 * round is left planned.
 */
class RoundWorkOrderSync
{
    public function __construct(private readonly UpdateWorkOrderStatusAction $status) {}

    public function sync(?string $roundId): void
    {
        if ($roundId === null) {
            return;
        }

        $round = CellarActivityRound::query()->whereKey($roundId)->first();
        if ($round === null || $round->work_order_id === null) {
            return;
        }

        $card = WorkOrder::query()->whereKey($round->work_order_id)->first();
        if ($card === null || $card->status->isClosed()) {
            return;
        }

        $earliestOpen = $round->activities()
            ->where('status', CellarActivityStatus::Planned->value)
            ->min('planned_for');

        if ($earliestOpen === null) {
            $this->status->execute($card, TaskStatus::Done);

            return;
        }

        $due = Carbon::parse(substr((string) $earliestOpen, 0, 10));
        if ($card->due_date?->toDateString() !== $due->toDateString()) {
            $card->due_date = $due;
            $card->save();
        }
    }
}
