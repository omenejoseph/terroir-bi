<?php

declare(strict_types=1);

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A cellar operation performed on a lot (racking, filtration, pump-over …).
 *
 * @property string $id
 * @property string $wine_lot_id
 * @property string|null $vessel_id
 * @property string $created_by_id
 * @property Carbon $date
 * @property string $kind
 * @property string|null $volume
 * @property string|null $note
 */
class CellarProcess extends Model
{
    use BelongsToTenant;
    use HasUlids;

    protected $fillable = [
        'wine_lot_id', 'vessel_id', 'created_by_id', 'date', 'kind', 'volume', 'note',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'datetime',
            'volume' => 'decimal:3',
        ];
    }

    /**
     * @return BelongsTo<WineLot, $this>
     */
    public function wineLot(): BelongsTo
    {
        return $this->belongsTo(WineLot::class);
    }
}
