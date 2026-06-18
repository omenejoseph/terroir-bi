<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Money\Money;
use App\Support\Money\MoneyCast;
use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A cellar additive (SO₂ salt, yeast, nutrient, tannin …) with stock and an
 * optional SO₂ uplift coefficient used by the dosing calculator.
 *
 * @property string $id
 * @property string $name
 * @property string $category
 * @property string $unit
 * @property string $current_stock
 * @property string|null $min_stock
 * @property Money|null $cost_per_unit
 * @property string|null $manufacturer
 * @property string|null $packaging_size
 * @property string|null $so2_uplift_per_unit
 * @property string|null $supplier_id
 * @property bool $is_active
 * @property string|null $notes
 */
class EnologicalProduct extends Model
{
    use BelongsToTenant;
    use HasUlids;

    protected $fillable = [
        'name', 'category', 'unit', 'current_stock', 'min_stock', 'cost_per_unit',
        'manufacturer', 'packaging_size', 'so2_uplift_per_unit', 'supplier_id',
        'is_active', 'notes',
    ];

    protected $attributes = [
        'current_stock' => 0,
        'is_active' => true,
    ];

    protected function casts(): array
    {
        return [
            'current_stock' => 'decimal:3',
            'min_stock' => 'decimal:3',
            'cost_per_unit' => MoneyCast::class,
            'so2_uplift_per_unit' => 'decimal:4',
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Supplier, $this>
     */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    /**
     * @return HasMany<CellarAddition, $this>
     */
    public function cellarAdditions(): HasMany
    {
        return $this->hasMany(CellarAddition::class);
    }
}
