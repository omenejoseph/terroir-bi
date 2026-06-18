<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\HarvestEntryStatus;
use App\Enums\HarvestSource;
use App\Support\Money\Money;
use App\Support\Money\MoneyCast;
use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A planned or recorded grape reception within a harvest plan.
 *
 * @property string $id
 * @property string $harvest_plan_id
 * @property string|null $parcel_id
 * @property string|null $wine_lot_id
 * @property HarvestEntryStatus $status
 * @property HarvestSource $source
 * @property string|null $actual_yield_kg
 * @property string|null $actual_volume_liters
 * @property Money|null $grape_price_per_kg
 * @property Carbon|null $planned_date
 * @property Carbon|null $actual_date
 */
class HarvestEntry extends Model
{
    use BelongsToTenant;
    use HasUlids;

    protected $fillable = [
        'harvest_plan_id', 'parcel_id', 'contract_id', 'supplier_id', 'planned_vessel_id', 'wine_lot_id',
        'status', 'source', 'grape_variety', 'planned_date', 'estimated_yield_kg', 'actual_date',
        'actual_yield_kg', 'actual_volume_liters', 'grape_price_per_kg', 'brix', 'ph', 'titrable_acidity',
        'temperature', 'condition_score', 'condition_notes', 'photo_url', 'notes',
    ];

    protected $attributes = ['status' => 'PLANNED', 'source' => 'OWN'];

    protected function casts(): array
    {
        return [
            'status' => HarvestEntryStatus::class,
            'source' => HarvestSource::class,
            'planned_date' => 'datetime',
            'actual_date' => 'datetime',
            'estimated_yield_kg' => 'decimal:3',
            'actual_yield_kg' => 'decimal:3',
            'actual_volume_liters' => 'decimal:3',
            'grape_price_per_kg' => MoneyCast::class,
            'brix' => 'decimal:2',
            'ph' => 'decimal:2',
            'titrable_acidity' => 'decimal:2',
            'temperature' => 'decimal:2',
            'condition_score' => 'integer',
        ];
    }

    /** @return BelongsTo<HarvestPlan, $this> */
    public function harvestPlan(): BelongsTo
    {
        return $this->belongsTo(HarvestPlan::class);
    }

    /** @return BelongsTo<VineyardParcel, $this> */
    public function parcel(): BelongsTo
    {
        return $this->belongsTo(VineyardParcel::class, 'parcel_id');
    }

    /** @return BelongsTo<GrapeContract, $this> */
    public function contract(): BelongsTo
    {
        return $this->belongsTo(GrapeContract::class, 'contract_id');
    }
}
