<?php

declare(strict_types=1);

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A named B2B price book with a default rebate. Per-item tier prices
 * (tier_prices) arrive with the Inventory module.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $name
 * @property string|null $description
 * @property string $rebate_percent
 */
class PricingTier extends Model
{
    use BelongsToTenant;
    use HasUlids;

    protected $fillable = [
        'name',
        'description',
        'rebate_percent',
    ];

    protected function casts(): array
    {
        return [
            'rebate_percent' => 'decimal:2',
        ];
    }

    /**
     * @return HasMany<Customer, $this>
     */
    public function customers(): HasMany
    {
        return $this->hasMany(Customer::class);
    }
}
