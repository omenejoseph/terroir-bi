<?php

declare(strict_types=1);

namespace App\Actions\Cellar\Activities;

use App\Enums\CellarActivityStatus;
use App\Models\CellarActivity;
use App\Models\CellarActivityRound;
use App\Services\Audit\AuditLogger;
use App\Services\Cellar\RoundWorkOrderSync;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/** Move every still-planned item of a round to another day. */
class RescheduleCellarRoundAction
{
    public function __construct(
        private readonly RoundWorkOrderSync $rounds,
        private readonly AuditLogger $audit,
    ) {}

    /** @return int number of items moved */
    public function execute(CellarActivityRound $round, string $date, ?string $reason): int
    {
        return DB::transaction(function () use ($round, $date, $reason): int {
            $open = $round->activities()
                ->where('status', CellarActivityStatus::Planned->value)
                ->lockForUpdate()
                ->get();

            foreach ($open as $activity) {
                /** @var CellarActivity $activity */
                $activity->planned_for = Carbon::parse($date);
                if ($reason !== null && $reason !== '') {
                    $activity->note = implode(' · ', array_filter([$activity->note, "⏭ {$reason}"]));
                }
                $activity->save();
            }

            $this->rounds->sync($round->getKey());

            $this->audit->record(Auth::user(), 'cellar_activity_round.rescheduled', $round, [
                'to' => $date,
                'reason' => $reason,
                'moved' => $open->count(),
            ]);

            return $open->count();
        });
    }
}
