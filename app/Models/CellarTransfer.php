<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CellarTransferType;
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
}
