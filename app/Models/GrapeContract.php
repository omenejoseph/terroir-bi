<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\GrapeContractStatus;
use App\Support\Money\Money;
use App\Support\Money\MoneyCast;
use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A grape supply contract with a cooperant supplier.
 *
 * @property string $id
 * @property string $supplier_id
 * @property string|null $parcel_id
 * @property string $season
 * @property GrapeContractStatus $status
 * @property string $grape_variety
 * @property string $estimated_kg
 * @property string $delivered_kg
 * @property Money $price_per_kg
 * @property string|null $min_brix
 * @property string|null $max_ph
 */
class GrapeContract extends Model
{
    use BelongsToTenant;
    use HasUlids;

    protected $fillable = [
        'supplier_id', 'parcel_id', 'season', 'status', 'grape_variety', 'estimated_kg',
        'delivered_kg', 'price_per_kg', 'min_brix', 'max_ph', 'delivery_window', 'payment_terms', 'notes',
    ];

    protected $attributes = ['status' => 'ACTIVE', 'delivered_kg' => 0];

    protected function casts(): array
    {
        return [
            'status' => GrapeContractStatus::class,
            'estimated_kg' => 'decimal:3',
            'delivered_kg' => 'decimal:3',
            'price_per_kg' => MoneyCast::class,
            'min_brix' => 'decimal:2',
            'max_ph' => 'decimal:2',
        ];
    }

    /** @return BelongsTo<Supplier, $this> */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }
}
