<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CellarActivityStatus;
use App\Enums\CellarActivityType;
use App\Enums\WineLotStatus;
use App\Enums\WineType;
use App\Support\Money\Money;
use App\Support\Money\MoneyCast;
use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * A wine lot / cuvée — the unit a cellar tracks from fermentation to bottling.
 * Its `current_volume` is the sum of the volumes across the vessels it occupies.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $lot_number
 * @property string $name
 * @property string $grape_variety
 * @property string $vintage
 * @property string|null $vineyard
 * @property WineType|null $wine_type
 * @property string $initial_volume
 * @property string $current_volume
 * @property WineLotStatus $status
 * @property Money|null $grape_cost
 * @property int $carried_cost cost carried in by blends (minor units)
 * @property Money|null $grape_price_per_kg
 * @property string|null $harvest_weight_kg
 * @property string|null $fermentation_template_id
 * @property string|null $notes
 */
class WineLot extends Model
{
    use BelongsToTenant;
    use HasUlids;

    protected $fillable = [
        'lot_number',
        'name',
        'grape_variety',
        'vintage',
        'vineyard',
        'wine_type',
        'initial_volume',
        'current_volume',
        'status',
        'grape_cost',
        'carried_cost',
        'grape_price_per_kg',
        'harvest_weight_kg',
        'fermentation_template_id',
        'notes',
    ];

    protected $attributes = [
        'status' => 'FERMENTING',
    ];

    protected function casts(): array
    {
        return [
            'wine_type' => WineType::class,
            'status' => WineLotStatus::class,
            'initial_volume' => 'decimal:3',
            'current_volume' => 'decimal:3',
            'grape_cost' => MoneyCast::class,
            'carried_cost' => 'integer',
            'grape_price_per_kg' => MoneyCast::class,
            'harvest_weight_kg' => 'decimal:3',
        ];
    }

    /**
     * @return BelongsTo<FermentationTemplate, $this>
     */
    public function fermentationTemplate(): BelongsTo
    {
        return $this->belongsTo(FermentationTemplate::class);
    }

    /**
     * @return HasMany<WineLotGrape, $this>
     */
    public function grapes(): HasMany
    {
        return $this->hasMany(WineLotGrape::class);
    }

    /**
     * @return HasMany<VesselLot, $this>
     */
    public function vesselLots(): HasMany
    {
        return $this->hasMany(VesselLot::class);
    }

    /**
     * @return HasMany<CellarAnalysis, $this>
     */
    public function analyses(): HasMany
    {
        return $this->hasMany(CellarAnalysis::class);
    }

    /**
     * The most recent analysis, for the cellar-map SO₂ badge.
     *
     * @return HasOne<CellarAnalysis, $this>
     */
    public function latestAnalysis(): HasOne
    {
        return $this->hasOne(CellarAnalysis::class)->latestOfMany('date');
    }

    /**
     * The most recent addition, for the active-lots table.
     *
     * @return HasOne<CellarAddition, $this>
     */
    public function latestAddition(): HasOne
    {
        return $this->hasOne(CellarAddition::class)->latestOfMany('created_at');
    }

    /**
     * Cost lines charged to this lot.
     *
     * @return HasMany<CostItem, $this>
     */
    public function costItems(): HasMany
    {
        return $this->hasMany(CostItem::class);
    }

    /**
     * @return HasOne<Bottling, $this>
     */
    public function latestBottling(): HasOne
    {
        return $this->hasOne(Bottling::class)->latestOfMany('date');
    }

    /**
     * @return HasMany<CellarAddition, $this>
     */
    public function additions(): HasMany
    {
        return $this->hasMany(CellarAddition::class);
    }

    /**
     * @return HasMany<CellarProcess, $this>
     */
    public function processes(): HasMany
    {
        return $this->hasMany(CellarProcess::class);
    }

    /**
     * @return HasMany<CellarTastingNote, $this>
     */
    public function tastingNotes(): HasMany
    {
        return $this->hasMany(CellarTastingNote::class);
    }

    /**
     * @return HasMany<Bottling, $this>
     */
    public function bottlings(): HasMany
    {
        return $this->hasMany(Bottling::class);
    }

    /**
     * Additions planned for this lot and not yet done ("waiting for the cellar team").
     *
     * @return HasMany<CellarActivity, $this>
     */
    public function plannedAdditions(): HasMany
    {
        return $this->hasMany(CellarActivity::class)
            ->where('type', CellarActivityType::Addition->value)
            ->where('status', CellarActivityStatus::Planned->value)
            ->orderBy('planned_for')
            ->orderBy('sort_order');
    }

    /**
     * @return HasMany<CellarTransfer, $this>
     */
    public function transfersOut(): HasMany
    {
        return $this->hasMany(CellarTransfer::class, 'from_lot_id');
    }

    /**
     * @return HasMany<CellarTransfer, $this>
     */
    public function transfersIn(): HasMany
    {
        return $this->hasMany(CellarTransfer::class, 'to_lot_id');
    }
}
