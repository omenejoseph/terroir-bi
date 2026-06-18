<?php

declare(strict_types=1);

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A lab analysis for a wine lot, optionally scoped to one vessel.
 *
 * @property string $id
 * @property string $wine_lot_id
 * @property string|null $vessel_id
 * @property string $created_by_id
 * @property Carbon $date
 * @property string|null $ph
 * @property string|null $total_acidity
 * @property string|null $volatile_acidity
 * @property string|null $alcohol
 * @property string|null $residual_sugar
 * @property string|null $free_so2
 * @property string|null $total_so2
 * @property string|null $brix
 * @property string|null $temperature
 * @property string|null $density
 * @property string|null $malic
 * @property string|null $lactic
 * @property string|null $tpi
 * @property string|null $glucose_fructose
 * @property string|null $note
 */
class CellarAnalysis extends Model
{
    use BelongsToTenant;
    use HasUlids;

    /** The measurement columns, in display order. */
    public const PARAMETERS = [
        'ph', 'total_acidity', 'volatile_acidity', 'alcohol', 'residual_sugar',
        'free_so2', 'total_so2', 'brix', 'temperature', 'density', 'malic',
        'lactic', 'tpi', 'glucose_fructose',
    ];

    protected $fillable = [
        'wine_lot_id', 'vessel_id', 'created_by_id', 'date',
        ...self::PARAMETERS, 'note',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'datetime',
            'ph' => 'decimal:2',
            'total_acidity' => 'decimal:2',
            'volatile_acidity' => 'decimal:3',
            'alcohol' => 'decimal:2',
            'residual_sugar' => 'decimal:2',
            'free_so2' => 'decimal:2',
            'total_so2' => 'decimal:2',
            'brix' => 'decimal:2',
            'temperature' => 'decimal:2',
            'density' => 'decimal:4',
            'malic' => 'decimal:2',
            'lactic' => 'decimal:2',
            'tpi' => 'decimal:2',
            'glucose_fructose' => 'decimal:2',
        ];
    }

    /**
     * @return BelongsTo<WineLot, $this>
     */
    public function wineLot(): BelongsTo
    {
        return $this->belongsTo(WineLot::class);
    }

    /**
     * @return BelongsTo<Vessel, $this>
     */
    public function vessel(): BelongsTo
    {
        return $this->belongsTo(Vessel::class);
    }
}
