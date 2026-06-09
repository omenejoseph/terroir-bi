<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ConsignmentReportKind;
use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $order_id
 * @property ConsignmentReportKind $kind
 * @property Carbon $date
 * @property string|null $note
 * @property string $created_by_id
 */
class ConsignmentReport extends Model
{
    use BelongsToTenant;
    use HasUlids;

    protected $fillable = [
        'order_id',
        'kind',
        'date',
        'note',
        'created_by_id',
    ];

    protected function casts(): array
    {
        return [
            'kind' => ConsignmentReportKind::class,
            'date' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * @return HasMany<ConsignmentReportItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(ConsignmentReportItem::class, 'report_id');
    }
}
