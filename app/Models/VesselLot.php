<?php

declare(strict_types=1);

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * The presence of one wine lot in one vessel, with its volume. A lot may span
 * several vessels; a vessel's `current_volume` is the sum of its vessel_lots.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $vessel_id
 * @property string $wine_lot_id
 * @property string $volume
 * @property Carbon|null $added_at
 */
class VesselLot extends Model
{
    use BelongsToTenant;
    use HasUlids;

    protected $fillable = [
        'vessel_id',
        'wine_lot_id',
        'volume',
        'added_at',
    ];

    protected function casts(): array
    {
        return [
            'volume' => 'decimal:3',
            'added_at' => 'datetime',
        ];
    }

    /**
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
