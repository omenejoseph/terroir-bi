<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CellarTransferType;
use App\Services\Audit\Auditable;
use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A recorded wine movement between lots/vessels (rack, blend, split).
 *
 * @property string $id
 * @property string $from_lot_id
 * @property string $to_lot_id
 * @property string|null $from_vessel_id
 * @property string|null $to_vessel_id
 * @property string $created_by_id
 * @property CellarTransferType $type
 * @property string $volume_liters
 * @property string|null $note
 */
class CellarTransfer extends Model
{
    use Auditable;
    use BelongsToTenant;
    use HasUlids;

    protected $fillable = [
        'from_lot_id', 'to_lot_id', 'from_vessel_id', 'to_vessel_id',
        'created_by_id', 'type', 'volume_liters', 'note',
    ];

    protected function casts(): array
    {
        return [
            'type' => CellarTransferType::class,
            'volume_liters' => 'decimal:3',
        ];
    }

    /**
     * @return BelongsTo<WineLot, $this>
     */
    public function fromLot(): BelongsTo
    {
        return $this->belongsTo(WineLot::class, 'from_lot_id');
    }

    /**
     * @return BelongsTo<WineLot, $this>
     */
    public function toLot(): BelongsTo
    {
        return $this->belongsTo(WineLot::class, 'to_lot_id');
    }

    /**
     * @return BelongsTo<Vessel, $this>
     */
    public function fromVessel(): BelongsTo
    {
        return $this->belongsTo(Vessel::class, 'from_vessel_id');
    }

    /**
     * @return BelongsTo<Vessel, $this>
     */
    public function toVessel(): BelongsTo
    {
        return $this->belongsTo(Vessel::class, 'to_vessel_id');
    }

    /**
     * Identifying fields written with the `created` audit entry.
     *
     * @return array<string, mixed>
     */
    protected static function auditCreateSnapshot(Model $model): array
    {
        $snapshot = [];
        foreach (['type', 'from_lot_id', 'to_lot_id', 'from_vessel_id', 'to_vessel_id', 'volume_liters'] as $field) {
            $value = $model->getAttribute($field);
            $snapshot[$field] = $value instanceof \BackedEnum ? $value->value : ($value instanceof \DateTimeInterface ? $value->format(DATE_ATOM) : ($value === null || is_scalar($value) ? $value : (string) $value));
        }

        return $snapshot;
    }
}
