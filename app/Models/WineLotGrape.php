<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Money\Money;
use App\Support\Money\MoneyCast;
use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One grape component of a (potentially blended) wine lot.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $wine_lot_id
 * @property string $grape_variety
 * @property string|null $percentage
 * @property Money|null $price_per_kg
 * @property string|null $weight_kg
 * @property string|null $harvest_entry_id
 */
class WineLotGrape extends Model
{
    use BelongsToTenant;
    use HasUlids;

    protected $fillable = [
        'wine_lot_id',
        'grape_variety',
        'percentage',
        'price_per_kg',
        'weight_kg',
        'harvest_entry_id',
    ];

    protected function casts(): array
    {
        return [
            'percentage' => 'decimal:2',
            'price_per_kg' => MoneyCast::class,
            'weight_kg' => 'decimal:3',
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
