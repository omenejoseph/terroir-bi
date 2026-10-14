<?php

declare(strict_types=1);

namespace App\Actions\Cellar\Activities;

use App\Models\CellarActivity;
use App\Services\Audit\AuditLogger;
use App\Services\Cellar\RoundWorkOrderSync;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Move one planned item to another day; the round's card due date follows. */
class RescheduleCellarActivityAction
{
    public function __construct(
        private readonly RoundWorkOrderSync $rounds,
        private readonly AuditLogger $audit,
    ) {}

    public function execute(CellarActivity $activity, string $date, ?string $reason): CellarActivity
    {
        return DB::transaction(function () use ($activity, $date, $reason): CellarActivity {
            /** @var CellarActivity $activity */
            $activity = CellarActivity::query()->whereKey($activity->getKey())->lockForUpdate()->firstOrFail();

            if (! $activity->isPlanned()) {
                throw ValidationException::withMessages(['planned_for' => 'Only planned work can be moved.']);
            }

            $from = $activity->planned_for->toDateString();
            $activity->planned_for = Carbon::parse($date);
            if ($reason !== null && $reason !== '') {
                $activity->note = implode(' · ', array_filter([$activity->note, "⏭ {$reason}"]));
            }
            $activity->save();

            $this->rounds->sync($activity->round_id);

            $this->audit->record(Auth::user(), 'cellar_activity.rescheduled', $activity, [
                'from' => $from,
                'to' => $date,
                'reason' => $reason,
            ]);

            return $activity;
        });
    }
}
