<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ReservationStatus;
use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One line of a reservation's timeline: a status change, or a note written while it sat in a status.
 *
 * @property string $id
 * @property string $reservation_id
 * @property ReservationStatus $status
 * @property string|null $note
 * @property string $changed_by_id
 * @property Carbon|null $created_at
 */
class ReservationStatusHistory extends Model
{
    use BelongsToTenant;
    use HasUlids;

    public const UPDATED_AT = null;

    protected $fillable = [
        'reservation_id',
        'status',
        'note',
        'changed_by_id',
    ];

    protected function casts(): array
    {
        return [
            'status' => ReservationStatus::class,
        ];
    }

    /**
     * @return BelongsTo<Reservation, $this>
     */
    public function reservation(): BelongsTo
    {
        return $this->belongsTo(Reservation::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by_id');
    }
}
