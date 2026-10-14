<?php

declare(strict_types=1);

namespace App\Actions\Cellar\Activities;

use App\Enums\CellarActivityStatus;
use App\Models\CellarActivity;
use App\Services\Cellar\RoundWorkOrderSync;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Remove one planned or skipped item. Executed work is history and stays.
 * The round's card is re-synced (closed if this was its last open item).
 */
class DeleteCellarActivityAction
{
    public function __construct(private readonly RoundWorkOrderSync $rounds) {}

    public function execute(CellarActivity $activity): void
    {
        DB::transaction(function () use ($activity): void {
            if ($activity->status === CellarActivityStatus::Done) {
                throw ValidationException::withMessages(['status' => 'This work has been done and is part of the record — it cannot be deleted.']);
            }

            $roundId = $activity->round_id;
            $activity->delete(); // Auditable logs cellar_activity.deleted
            $this->rounds->sync($roundId);
        });
    }
}
