<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\VillaOrderStatus;
use App\Support\Money\Money;
use App\Support\Money\MoneyCast;
use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $booking_id
 * @property VillaOrderStatus $status
 * @property Money $total
 * @property Carbon|null $delivery_date
 * @property string|null $notes
 * @property string|null $stripe_payment_id
 * @property Carbon|null $paid_at
 * @property Carbon|null $created_at
 */
class VillaOrder extends Model
{
    use BelongsToTenant;
    use HasUlids;

    protected $fillable = ['booking_id', 'status', 'total', 'delivery_date', 'notes', 'stripe_payment_id', 'paid_at'];

    protected $attributes = ['status' => 'PENDING', 'total' => 0];

    protected function casts(): array
    {
        return [
            'status' => VillaOrderStatus::class,
            'total' => MoneyCast::class,
            'delivery_date' => 'datetime',
            'paid_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<VillaBooking, $this>
     */
    public function booking(): BelongsTo
    {
        return $this->belongsTo(VillaBooking::class, 'booking_id');
    }

    /**
     * @return HasMany<VillaOrderItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(VillaOrderItem::class, 'order_id');
    }
}
