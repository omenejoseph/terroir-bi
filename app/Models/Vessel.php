<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\VesselStatus;
use App\Enums\VesselType;
use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A cellar vessel (tank, barrel, vat …). `current_volume` is derived from the
 * sum of its `vessel_lots` by VesselVolumeSync — never set it directly.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $name
 * @property VesselType $type
 * @property string|null $material
 * @property string $capacity_liters
 * @property string $current_volume
 * @property string|null $location
 * @property VesselStatus $status
 * @property bool $is_active
 * @property bool $is_faulty
 * @property string|null $fault_note
 * @property string $room
 * @property int|null $position_x
 * @property int|null $position_y
 * @property int|null $map_width
 * @property int|null $map_height
 * @property int|null $rotation
 * @property string|null $notes
 */
class Vessel extends Model
{
    use BelongsToTenant;
    use HasUlids;

    protected $fillable = [
        'name',
        'type',
        'material',
        'capacity_liters',
        'current_volume',
        'location',
        'status',
        'is_active',
        'is_faulty',
        'fault_note',
        'room',
        'position_x',
        'position_y',
        'map_width',
        'map_height',
        'rotation',
        'notes',
    ];

    protected $attributes = [
        'status' => 'AVAILABLE',
        'current_volume' => 0,
        'is_active' => true,
        'is_faulty' => false,
        'room' => 'Main Cellar',
    ];

    protected function casts(): array
    {
        return [
            'type' => VesselType::class,
            'status' => VesselStatus::class,
            'capacity_liters' => 'decimal:3',
            'current_volume' => 'decimal:3',
            'is_active' => 'boolean',
            'is_faulty' => 'boolean',
            'position_x' => 'integer',
            'position_y' => 'integer',
            'map_width' => 'integer',
            'map_height' => 'integer',
            'rotation' => 'integer',
        ];
    }

    /**
     * @return HasMany<VesselLot, $this>
     */
    public function vesselLots(): HasMany
    {
        return $this->hasMany(VesselLot::class);
    }
}
