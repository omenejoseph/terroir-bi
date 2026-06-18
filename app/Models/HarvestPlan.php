<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\HarvestPlanStatus;
use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $id
 * @property string $name
 * @property string $season
 * @property HarvestPlanStatus $status
 * @property string $yield_ratio
 */
class HarvestPlan extends Model
{
    use BelongsToTenant;
    use HasUlids;

    protected $fillable = ['created_by_id', 'name', 'season', 'status', 'yield_ratio', 'notes'];

    protected $attributes = ['status' => 'PLANNING', 'yield_ratio' => 0.650];

    protected function casts(): array
    {
        return ['status' => HarvestPlanStatus::class, 'yield_ratio' => 'decimal:3'];
    }

    /** @return HasMany<HarvestEntry, $this> */
    public function entries(): HasMany
    {
        return $this->hasMany(HarvestEntry::class);
    }

    /** @return HasMany<IntakeBooking, $this> */
    public function bookings(): HasMany
    {
        return $this->hasMany(IntakeBooking::class);
    }
}
