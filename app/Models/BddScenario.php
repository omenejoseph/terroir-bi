<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\BddRunStatus;
use App\Enums\BddScenarioStatus;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * An admin-authored BDD scenario (central, not tenant-scoped). `gherkin` is the
 * source of truth and is executed LIVE by an AI agent on every run (see
 * App\Services\Bdd\LiveScenarioRunner) inside an always-rolled-back sandbox
 * transaction. The compile-era columns (compiled_plan, requested_operations,
 * compile_error, compile_model) are dormant — no longer written, kept only for
 * reversibility until a cleanup migration drops them.
 *
 * @property string $id
 * @property string $title
 * @property string $slug
 * @property string $gherkin
 * @property BddScenarioStatus $status
 * @property array<string, mixed>|null $compiled_plan
 * @property list<array<string, string>>|null $requested_operations
 * @property string|null $compile_error
 * @property string|null $compile_model
 * @property bool $is_active
 * @property BddRunStatus|null $last_run_status
 * @property Carbon|null $last_run_at
 * @property string|null $created_by_id
 */
class BddScenario extends Model
{
    use HasUlids;

    protected $fillable = [
        'title',
        'slug',
        'gherkin',
        'status',
        'compiled_plan',
        'requested_operations',
        'compile_error',
        'compile_model',
        'is_active',
        'last_run_status',
        'last_run_at',
        'created_by_id',
    ];

    protected function casts(): array
    {
        return [
            'status' => BddScenarioStatus::class,
            'compiled_plan' => 'array',
            'requested_operations' => 'array',
            'is_active' => 'boolean',
            'last_run_status' => BddRunStatus::class,
            'last_run_at' => 'datetime',
        ];
    }

    /** Live runs need only Gherkin to execute — there is no compile step. */
    public function isRunnable(): bool
    {
        return trim($this->gherkin) !== '';
    }

    /**
     * @return HasMany<BddScenarioRun, $this>
     */
    public function runs(): HasMany
    {
        return $this->hasMany(BddScenarioRun::class);
    }
}
