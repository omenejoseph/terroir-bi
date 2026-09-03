<?php

declare(strict_types=1);

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A named Work Orders board (Figma 267:1781's "Cellar Operations", "Vineyard
 * & Maintenance") — independent of a task's `category` (what kind of work it
 * is). See App\Services\Tasks\WorkOrderBoardPresenter for the board picker
 * and columns this feeds.
 *
 * @property string $id
 * @property string $name
 * @property string $created_by_id
 * @property int $sort_order
 */
class WorkOrderBoard extends Model
{
    use BelongsToTenant;
    use HasUlids;

    protected $fillable = ['name', 'created_by_id', 'sort_order'];

    protected $attributes = [
        'sort_order' => 0,
    ];

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }

    /**
     * @return HasMany<WorkOrder, $this>
     */
    public function workOrders(): HasMany
    {
        return $this->hasMany(WorkOrder::class, 'board_id');
    }
}
