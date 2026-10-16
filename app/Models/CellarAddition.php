<?php

declare(strict_types=1);

namespace App\Models;

use App\Services\Audit\Auditable;
use App\Support\Money\Money;
use App\Support\Money\MoneyCast;
use App\Support\Money\UnitPrice;
use App\Support\Money\UnitPriceCast;
use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An enological addition (dose) applied to a wine lot.
 *
 * @property string $id
 * @property string $wine_lot_id
 * @property string|null $vessel_id
 * @property string|null $enological_product_id
 * @property string $created_by_id
 * @property string $name
 * @property string|null $category
 * @property string $quantity
 * @property string $unit
 * @property UnitPrice|null $cost_per_unit
 * @property Money|null $total_cost
 * @property string|null $note
 */
class CellarAddition extends Model
{
    use Auditable;
    use BelongsToTenant;
    use HasUlids;

    protected $fillable = [
        'wine_lot_id', 'vessel_id', 'enological_product_id', 'created_by_id', 'name',
        'category', 'quantity', 'unit', 'cost_per_unit', 'total_cost', 'note',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:3',
            'cost_per_unit' => UnitPriceCast::class,
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
     * @return BelongsTo<Vessel, $this>
     */
    public function vessel(): BelongsTo
    {
        return $this->belongsTo(Vessel::class);
    }

    /**
     * @return BelongsTo<EnologicalProduct, $this>
     */
    public function enologicalProduct(): BelongsTo
    {
        return $this->belongsTo(EnologicalProduct::class);
    }

    /**
     * Identifying fields written with the `created` audit entry.
     *
     * @return array<string, mixed>
     */
    protected static function auditCreateSnapshot(Model $model): array
    {
        $snapshot = [];
        foreach (['wine_lot_id', 'vessel_id', 'name', 'quantity', 'unit', 'enological_product_id'] as $field) {
            $value = $model->getAttribute($field);
            $snapshot[$field] = $value instanceof \BackedEnum ? $value->value : ($value instanceof \DateTimeInterface ? $value->format(DATE_ATOM) : ($value === null || is_scalar($value) ? $value : (string) $value));
        }

        return $snapshot;
    }
}
