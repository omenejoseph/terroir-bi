<?php

declare(strict_types=1);

namespace App\Models;

use App\Services\Audit\Auditable;
use App\Support\Money\Money;
use App\Support\Money\MoneyCast;
use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Something the winery sells to groups per head ("Classic tasting", "Vineyard tour + lunch").
 * Deactivating retires it from new reservations without touching the ones already taken.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $name
 * @property string|null $description
 * @property int|null $duration_mins
 * @property Money $base_price
 * @property int|null $max_pax
 * @property bool $is_active
 * @property int $sort_order
 */
class HospitalityProgram extends Model
{
    use Auditable;
    use BelongsToTenant;
    use HasUlids;

    protected $fillable = [
        'name',
        'description',
        'duration_mins',
        'base_price',
        'max_pax',
        'is_active',
        'sort_order',
    ];

    protected $attributes = [
        'base_price' => 0,
        'is_active' => true,
        'sort_order' => 0,
    ];

    protected function casts(): array
    {
        return [
            'base_price' => MoneyCast::class,
            'duration_mins' => 'integer',
            'max_pax' => 'integer',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    /**
     * @return HasMany<HospitalityAgencyPrice, $this>
     */
    public function agencyPrices(): HasMany
    {
        return $this->hasMany(HospitalityAgencyPrice::class, 'program_id');
    }

    /**
     * @return HasMany<Reservation, $this>
     */
    public function reservations(): HasMany
    {
        return $this->hasMany(Reservation::class, 'program_id');
    }
}
