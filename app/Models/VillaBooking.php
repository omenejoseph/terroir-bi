<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\VillaBookingStatus;
use App\Services\Audit\Auditable;
use App\Support\Money\Money;
use App\Support\Money\MoneyCast;
use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A stay. `auto_calculated_amount` is what the rates gave when it was saved; `total_amount` is the
 * agreed figure, which may be overridden. Changing the dates later does not recompute either.
 *
 * @property string $id
 * @property string $villa_id
 * @property string $guest_name
 * @property string|null $guest_email
 * @property string|null $guest_phone
 * @property Carbon $check_in
 * @property Carbon $check_out
 * @property int $guests
 * @property string $portal_token
 * @property string|null $notes
 * @property VillaBookingStatus $status
 * @property Money|null $total_amount
 * @property string $rebate_percent
 * @property Money|null $auto_calculated_amount
 * @property Money|null $deposit_amount
 * @property bool $deposit_paid
 */
class VillaBooking extends Model
{
    use Auditable;
    use BelongsToTenant;
    use HasUlids;

    protected $fillable = [
        'villa_id', 'guest_name', 'guest_email', 'guest_phone', 'check_in', 'check_out', 'guests', 'portal_token',
        'notes', 'status', 'total_amount', 'rebate_percent', 'auto_calculated_amount', 'deposit_amount', 'deposit_paid',
    ];

    protected $attributes = ['status' => 'UPCOMING', 'guests' => 2, 'rebate_percent' => 0, 'deposit_paid' => false];

    protected function casts(): array
    {
        return [
            'check_in' => 'date',
            'check_out' => 'date',
            'guests' => 'integer',
            'status' => VillaBookingStatus::class,
            'total_amount' => MoneyCast::class,
            'rebate_percent' => 'decimal:2',
            'auto_calculated_amount' => MoneyCast::class,
            'deposit_amount' => MoneyCast::class,
            'deposit_paid' => 'boolean',
        ];
    }

    public function nights(): int
    {
        return (int) $this->check_in->diffInDays($this->check_out);
    }

    /**
     * @return BelongsTo<Villa, $this>
     */
    public function villa(): BelongsTo
    {
        return $this->belongsTo(Villa::class);
    }

    /**
     * @return HasMany<VillaOrder, $this>
     */
    public function orders(): HasMany
    {
        return $this->hasMany(VillaOrder::class, 'booking_id')->orderByDesc('created_at');
    }
}
