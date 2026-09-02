<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Enums\WorkOrderCategory;
use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A team task / work order.
 *
 * @property string $id
 * @property string $title
 * @property string|null $description
 * @property WorkOrderCategory|null $category
 * @property TaskPriority $priority
 * @property TaskStatus $status
 * @property Carbon|null $start_date
 * @property Carbon|null $due_date
 * @property Carbon|null $completed_at
 * @property int $sort_order
 * @property string|null $assignee_id
 * @property string $created_by_id
 * @property string|null $vessel_id
 * @property string|null $wine_lot_id
 */
class WorkOrder extends Model
{
    use BelongsToTenant;
    use HasUlids;

    protected $fillable = [
        'title', 'description', 'category', 'priority', 'status',
        'start_date', 'due_date', 'completed_at', 'sort_order', 'assignee_id', 'created_by_id',
        'wine_lot_id', 'vessel_id',
    ];

    protected $attributes = [
        'priority' => 'MEDIUM',
        'status' => 'TODO',
        'sort_order' => 0,
    ];

    protected function casts(): array
    {
        return [
            'priority' => TaskPriority::class,
            'status' => TaskStatus::class,
            'category' => WorkOrderCategory::class,
            'start_date' => 'datetime',
            'due_date' => 'datetime',
            'completed_at' => 'datetime',
            'sort_order' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assignee_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }

    /**
     * The vessel the work happens in. The columns have existed since the table
     * was created but had no relation; the board's card names it ("Tank A-2",
     * "Barrel 042" on 267:1781), which is what finally needed one.
     *
     * @return BelongsTo<Vessel, $this>
     */
    public function vessel(): BelongsTo
    {
        return $this->belongsTo(Vessel::class);
    }

    /**
     * @return BelongsTo<WineLot, $this>
     */
    public function wineLot(): BelongsTo
    {
        return $this->belongsTo(WineLot::class);
    }
}
