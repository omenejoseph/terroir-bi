<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\BddRunStatus;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One live execution of a BDD scenario: the verdict, the per-step trace and
 * the full AI tool transcript. Written AFTER the sandbox transaction rolls
 * back, so the record survives while everything the run created vanishes.
 *
 * @property string $id
 * @property string $bdd_scenario_id
 * @property BddRunStatus $status
 * @property list<array<string, mixed>>|null $step_results
 * @property string|null $error
 * @property list<array<string, mixed>>|null $transcript
 * @property list<string>|null $logs
 * @property int $duration_ms
 * @property string|null $triggered_by_id
 * @property Carbon|null $created_at
 */
class BddScenarioRun extends Model
{
    use HasUlids;

    protected $fillable = [
        'bdd_scenario_id',
        'status',
        'step_results',
        'error',
        'transcript',
        'logs',
        'duration_ms',
        'triggered_by_id',
    ];

    protected function casts(): array
    {
        return [
            'status' => BddRunStatus::class,
            'step_results' => 'array',
            'transcript' => 'array',
            'logs' => 'array',
            'duration_ms' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<BddScenario, $this>
     */
    public function scenario(): BelongsTo
    {
        return $this->belongsTo(BddScenario::class, 'bdd_scenario_id');
    }
}
