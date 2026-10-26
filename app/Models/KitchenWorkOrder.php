<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\KitchenWorkOrderStatus;
use App\Services\Audit\Auditable;
use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A kitchen prep ticket: what to prepare on a day and for whom. Never touches stock.
 *
 * @property string $id
 * @property string $title
 * @property Carbon $date
 * @property KitchenWorkOrderStatus $status
 * @property string|null $assigned_to
 * @property string|null $notes
 * @property Carbon|null $completed_at
 * @property string $user_id
 */
class KitchenWorkOrder extends Model
{
    use Auditable;
    use BelongsToTenant;
    use HasUlids;

    protected $fillable = ['title', 'date', 'status', 'assigned_to', 'notes', 'completed_at', 'user_id'];

    protected $attributes = ['status' => 'PLANNED'];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'status' => KitchenWorkOrderStatus::class,
            'completed_at' => 'datetime',
        ];
    }

    /**
     * @return HasMany<KitchenWorkOrderItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(KitchenWorkOrderItem::class, 'work_order_id')->orderBy('sort_order');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
