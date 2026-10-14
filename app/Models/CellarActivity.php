<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CellarActivityStatus;
use App\Enums\CellarActivityType;
use App\Services\Audit\Auditable;
use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One piece of planned cellar work. See App\Services\Cellar\ActivityPayload
 * for the per-type `payload` shape.
 *
 * @property string $id
 * @property CellarActivityType $type
 * @property CellarActivityStatus $status
 * @property array<string, mixed> $payload
 * @property Carbon $planned_for
 * @property int $sort_order
 * @property string|null $wine_lot_id
 * @property string|null $vessel_id
 * @property string|null $round_id
 * @property string|null $assigned_to_id
 * @property string|null $note
 * @property string|null $skip_reason
 * @property bool $deviated
 * @property Carbon|null $applied_at
 * @property Carbon|null $skipped_at
 * @property string $planned_by_id
 * @property string|null $executed_by_id
 */
class CellarActivity extends Model
{
    use Auditable;
    use BelongsToTenant;
    use HasUlids;

    protected $fillable = [
        'type', 'status', 'payload', 'planned_for', 'sort_order', 'wine_lot_id', 'vessel_id',
        'round_id', 'assigned_to_id', 'note', 'skip_reason', 'deviated', 'applied_at',
        'skipped_at', 'planned_by_id', 'executed_by_id',
    ];

    protected $attributes = [
        'status' => 'PLANNED',
        'sort_order' => 0,
        'deviated' => false,
    ];

    protected function casts(): array
    {
        return [
            'type' => CellarActivityType::class,
            'status' => CellarActivityStatus::class,
            'payload' => 'array',
            'planned_for' => 'date',
            'sort_order' => 'integer',
            'deviated' => 'boolean',
            'applied_at' => 'datetime',
            'skipped_at' => 'datetime',
        ];
    }

    public function isPlanned(): bool
    {
        return $this->status === CellarActivityStatus::Planned;
    }

    /**
     * @return BelongsTo<WineLot, $this>
     */
    public function wineLot(): BelongsTo
    {
        return $this->belongsTo(WineLot::class);
    }

    /**
     * @return BelongsTo<Vessel, $this>
     */
    public function vessel(): BelongsTo
    {
        return $this->belongsTo(Vessel::class);
    }

    /**
     * @return BelongsTo<CellarActivityRound, $this>
     */
    public function round(): BelongsTo
    {
        return $this->belongsTo(CellarActivityRound::class, 'round_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function assignedTo(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function plannedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'planned_by_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function executedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'executed_by_id');
    }

    /**
     * @return array<string, mixed>
     */
    protected static function auditCreateSnapshot(Model $model): array
    {
        return [
            'type' => $model->getAttribute('type')?->value,
            'planned_for' => $model->getAttribute('planned_for')?->toDateString(),
            'wine_lot_id' => $model->getAttribute('wine_lot_id'),
            'vessel_id' => $model->getAttribute('vessel_id'),
            'round_id' => $model->getAttribute('round_id'),
        ];
    }

    /**
     * Execution fields get a richer, named entry from the Activities actions
     * (cellar_activity.completed / .skipped / .rescheduled).
     *
     * @return list<string>
     */
    protected static function auditIgnoreOnUpdate(): array
    {
        return ['status', 'payload', 'deviated', 'applied_at', 'skipped_at', 'executed_by_id', 'skip_reason', 'planned_for', 'note'];
    }
}
