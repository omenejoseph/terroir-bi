<?php

declare(strict_types=1);

namespace App\Models;

use App\Services\Audit\Auditable;
use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A named batch of planned cellar activities, mirrored as one card on the
 * default Work Orders board (`work_order_id`).
 *
 * @property string $id
 * @property string $name
 * @property string|null $work_order_id
 * @property string $created_by_id
 */
class CellarActivityRound extends Model
{
    use Auditable;
    use BelongsToTenant;
    use HasUlids;

    protected $fillable = ['name', 'work_order_id', 'created_by_id'];

    /**
     * @return HasMany<CellarActivity, $this>
     */
    public function activities(): HasMany
    {
        return $this->hasMany(CellarActivity::class, 'round_id');
    }

    /**
     * @return BelongsTo<WorkOrder, $this>
     */
    public function workOrder(): BelongsTo
    {
        return $this->belongsTo(WorkOrder::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }

    /**
     * @return array<string, mixed>
     */
    protected static function auditCreateSnapshot(Model $model): array
    {
        return ['name' => $model->getAttribute('name')];
    }
}
