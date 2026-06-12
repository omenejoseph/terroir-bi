<?php

declare(strict_types=1);

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $id
 * @property string $company_name
 * @property string|null $tax_id
 * @property bool $is_active
 * @property bool $exclude_from_stats
 * @property string|null $portal_token
 */
class Supplier extends Model
{
    use BelongsToTenant;
    use HasUlids;

    protected $fillable = [
        'company_name',
        'contact_name',
        'email',
        'phone',
        'address',
        'city',
        'country',
        'tax_id',
        'bank_account',
        'payment_terms',
        'notes',
        'is_active',
        'exclude_from_stats',
        'portal_token',
        'is_ai_generated',
        'ai_metadata',
    ];

    protected $attributes = [
        'is_active' => true,
        'exclude_from_stats' => false,
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'exclude_from_stats' => 'boolean',
            'is_ai_generated' => 'boolean',
            'ai_metadata' => 'array',
        ];
    }

    /**
     * @return HasMany<SupplierPriceItem, $this>
     */
    public function priceItems(): HasMany
    {
        return $this->hasMany(SupplierPriceItem::class);
    }

    /**
     * @return HasMany<SupplierOrder, $this>
     */
    public function orders(): HasMany
    {
        return $this->hasMany(SupplierOrder::class);
    }

    /**
     * @return HasMany<SupplierPriceChange, $this>
     */
    public function priceChanges(): HasMany
    {
        return $this->hasMany(SupplierPriceChange::class);
    }
}
