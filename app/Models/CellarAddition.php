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
 * An enological addition (dose) applied to a wine lot.
 *
 * @property string $id
 * @property string $wine_lot_id
 * @property string|null $enological_product_id
 * @property string $created_by_id
 * @property string $name
 * @property string|null $category
 * @property string $quantity
 * @property string $unit
 * @property Money|null $cost_per_unit
 * @property Money|null $total_cost
 * @property string|null $note
 */
class CellarAddition extends Model
{
    use BelongsToTenant;
    use HasUlids;

    protected $fillable = [
        'wine_lot_id', 'enological_product_id', 'created_by_id', 'name',
        'category', 'quantity', 'unit', 'cost_per_unit', 'total_cost', 'note',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:3',
            'cost_per_unit' => MoneyCast::class,
            'total_cost' => MoneyCast::class,
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
     * @return BelongsTo<EnologicalProduct, $this>
     */
    public function enologicalProduct(): BelongsTo
    {
        return $this->belongsTo(EnologicalProduct::class);
    }
}
