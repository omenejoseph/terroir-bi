<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Money\Money;
use App\Support\Money\MoneyCast;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A SaaS subscription plan. Central (not tenant-scoped).
 *
 * @property string $id
 * @property string $name
 * @property string $slug
 * @property Money|null $price
 * @property string $currency
 */
class Plan extends Model
{
    use HasUlids;

    protected $fillable = [
        'name',
        'slug',
        'price_minor',
        'currency',
    ];

    protected function casts(): array
    {
        return [
            // price_minor (bigint) + currency column -> Money value object.
            'price_minor' => MoneyCast::class.':currency',
        ];
    }

    public function tenants(): HasMany
    {
        return $this->hasMany(Tenant::class);
    }
}
