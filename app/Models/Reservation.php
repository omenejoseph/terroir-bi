<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ReservationStatus;
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
 * A group booked onto a program on a date. `price_per_pax` and `total_amount` are snapshots taken
 * when the booking was priced; a null customer is a walk-in.
 *
 * @property string $id
 * @property string $tenant_id
 * @property Carbon $date
 * @property int $pax
 * @property ReservationStatus $status
 * @property Money $price_per_pax
 * @property Money $total_amount
 * @property string|null $guest_name
 * @property string|null $guest_email
 * @property string|null $guest_phone
 * @property string|null $allergies
 * @property string|null $notes
 * @property string|null $cancel_reason
 * @property string $program_id
 * @property string|null $customer_id
 * @property string $created_by_id
 */
class Reservation extends Model
{
    use Auditable;
    use BelongsToTenant;
    use HasUlids;

    protected $fillable = [
        'date',
        'pax',
        'status',
        'price_per_pax',
        'total_amount',
        'guest_name',
        'guest_email',
        'guest_phone',
        'allergies',
        'notes',
        'cancel_reason',
        'program_id',
        'customer_id',
        'created_by_id',
    ];

    protected $attributes = [
        'status' => 'PENDING',
        'price_per_pax' => 0,
        'total_amount' => 0,
    ];

    protected function casts(): array
    {
        return [
            'date' => 'datetime',
            'pax' => 'integer',
            'status' => ReservationStatus::class,
            'price_per_pax' => MoneyCast::class,
            'total_amount' => MoneyCast::class,
        ];
    }

    /**
     * The status change gets its own hand-named audit entry (and a history row).
     *
     * @return list<string>
     */
    protected static function auditIgnoreOnUpdate(): array
    {
        return ['status'];
    }

    /**
     * @return BelongsTo<HospitalityProgram, $this>
     */
    public function program(): BelongsTo
    {
        return $this->belongsTo(HospitalityProgram::class, 'program_id');
    }

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }

    /**
     * @return HasMany<ReservationStatusHistory, $this>
     */
    public function statusHistories(): HasMany
    {
        // ULIDs break ties between lines written in the same second, in the order they were written.
        return $this->hasMany(ReservationStatusHistory::class)->orderBy('created_at')->orderBy('id');
    }
}
