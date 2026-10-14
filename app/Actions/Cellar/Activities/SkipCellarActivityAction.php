<?php

declare(strict_types=1);

namespace App\Actions\Cellar\Activities;

use App\Enums\CellarActivitySkipReason;
use App\Enums\CellarActivityStatus;
use App\Models\CellarActivity;
use App\Services\Audit\AuditLogger;
use App\Services\Cellar\RoundWorkOrderSync;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Mark planned work as not done. "Move to another day" is a reschedule
 * (RescheduleCellarActivityAction), not a skip.
 */
class SkipCellarActivityAction
{
    public function __construct(
        private readonly RoundWorkOrderSync $rounds,
        private readonly AuditLogger $audit,
    ) {}

    public function execute(CellarActivity $activity, CellarActivitySkipReason $reason, ?string $note, string $userId): CellarActivity
    {
        return DB::transaction(function () use ($activity, $reason, $note, $userId): CellarActivity {
            /** @var CellarActivity $activity */
            $activity = CellarActivity::query()->whereKey($activity->getKey())->lockForUpdate()->firstOrFail();

            if (! $activity->isPlanned()) {
                throw ValidationException::withMessages(['status' => 'Only planned work can be skipped.']);
            }

            $activity->status = CellarActivityStatus::Skipped;
            $activity->skip_reason = $reason->value;
            $activity->skipped_at = now();
            $activity->executed_by_id = $userId;
            if ($note !== null && $note !== '') {
                $activity->note = implode(' · ', array_filter([$activity->note, $note]));
            }
            $activity->save();

            $this->rounds->sync($activity->round_id);

            $this->audit->record(Auth::user(), 'cellar_activity.skipped', $activity, [
                'type' => $activity->type->value,
                'reason' => $reason->value,
                'note' => $note,
            ]);

            return $activity;
        });
    }
}
