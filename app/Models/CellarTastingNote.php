<?php

declare(strict_types=1);

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A tasting note for a wine lot, optionally vessel-scoped and grouped in a report.
 *
 * @property string $id
 * @property string $wine_lot_id
 * @property string|null $vessel_id
 * @property string|null $tasting_report_id
 * @property string $created_by_id
 * @property Carbon $date
 * @property string|null $appearance
 * @property string|null $nose
 * @property string|null $palate
 * @property string|null $overall
 * @property int|null $score
 * @property string|null $note
 */
class CellarTastingNote extends Model
{
    use BelongsToTenant;
    use HasUlids;

    protected $fillable = [
        'wine_lot_id', 'vessel_id', 'tasting_report_id', 'created_by_id', 'date',
        'appearance', 'nose', 'palate', 'overall', 'score', 'note',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'datetime',
            'score' => 'integer',
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
