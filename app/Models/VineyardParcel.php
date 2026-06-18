<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ParcelOwnership;
use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A vineyard parcel (plot). Estate-owned or farmed by a cooperant supplier.
 *
 * @property string $id
 * @property string $name
 * @property string $grape_variety
 * @property string|null $area_hectares
 * @property ParcelOwnership $ownership
 * @property string|null $cooperant_supplier_id
 * @property bool $is_active
 */
class VineyardParcel extends Model
{
    use BelongsToTenant;
    use HasUlids;

    protected $fillable = [
        'name', 'grape_variety', 'area_hectares', 'location', 'elevation', 'soil_type',
        'planting_year', 'row_spacing', 'vine_count', 'rootstock', 'training', 'orientation',
        'slope', 'latitude', 'longitude', 'geo_polygon', 'geo_area_calculated', 'ownership',
        'cooperant_supplier_id', 'is_active', 'notes',
    ];

    protected $attributes = [
        'ownership' => 'OWN',
        'is_active' => true,
    ];

    protected function casts(): array
    {
        return [
            'ownership' => ParcelOwnership::class,
            'area_hectares' => 'decimal:4',
            'row_spacing' => 'decimal:2',
            'slope' => 'decimal:2',
            'latitude' => 'decimal:6',
            'longitude' => 'decimal:6',
            'geo_polygon' => 'array',
            'geo_area_calculated' => 'decimal:4',
            'elevation' => 'integer',
            'planting_year' => 'integer',
            'vine_count' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /** @return BelongsTo<Supplier, $this> */
    public function cooperantSupplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class, 'cooperant_supplier_id');
    }

    /** @return HasMany<PhenologyLog, $this> */
    public function phenologyLogs(): HasMany
    {
        return $this->hasMany(PhenologyLog::class, 'parcel_id');
    }

    /** @return HasMany<MaturitySample, $this> */
    public function maturitySamples(): HasMany
    {
        return $this->hasMany(MaturitySample::class, 'parcel_id');
    }

    /** @return HasMany<CropEstimate, $this> */
    public function cropEstimates(): HasMany
    {
        return $this->hasMany(CropEstimate::class, 'parcel_id');
    }

    /** @return HasMany<VineyardApplication, $this> */
    public function applications(): HasMany
    {
        return $this->hasMany(VineyardApplication::class, 'parcel_id');
    }
}
