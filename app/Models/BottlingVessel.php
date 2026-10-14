<?php

declare(strict_types=1);

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Litres a bottling run drew from one vessel.
 *
 * @property string $id
 * @property string $bottling_id
 * @property string $vessel_id
 * @property string $volume
 */
class BottlingVessel extends Model
{
    use BelongsToTenant;
    use HasUlids;

    protected $fillable = ['bottling_id', 'vessel_id', 'volume'];

    protected function casts(): array
    {
        return ['volume' => 'decimal:3'];
    }

    /**
     * @return BelongsTo<Vessel, $this>
     */
    public function vessel(): BelongsTo
    {
        return $this->belongsTo(Vessel::class);
    }
}
