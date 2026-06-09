<?php

declare(strict_types=1);

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A B2B customer of the tenant.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $company_name
 * @property string|null $contact_name
 * @property string $email
 * @property bool $is_active
 * @property string $rebate_percent
 * @property bool $hide_prices
 * @property string|null $order_token
 * @property string|null $pricing_tier_id
 * @property string|null $customer_type
 * @property string|null $oib
 * @property bool $is_agency
 * @property bool $allow_single_bottle
 * @property Carbon|null $reorder_contacted_at
 */
class Customer extends Model
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
        'state',
        'zip',
        'country',
        'notes',
        'customer_type',
        'oib',
        'is_active',
        'rebate_percent',
        'exclude_from_stats',
        'hide_prices',
        'is_agency',
        'allow_single_bottle',
        'pricing_tier_id',
        'reorder_contacted_at',
    ];

    protected $attributes = [
        'is_active' => true,
        'rebate_percent' => 0,
        'exclude_from_stats' => false,
        'hide_prices' => false,
        'is_agency' => false,
        'allow_single_bottle' => false,
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'exclude_from_stats' => 'boolean',
            'hide_prices' => 'boolean',
            'is_agency' => 'boolean',
            'allow_single_bottle' => 'boolean',
            'rebate_percent' => 'decimal:2',
            'reorder_contacted_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<PricingTier, $this>
     */
    public function pricingTier(): BelongsTo
    {
        return $this->belongsTo(PricingTier::class);
    }

    /**
     * @return HasMany<CustomerProductOverride, $this>
     */
    public function productOverrides(): HasMany
    {
        return $this->hasMany(CustomerProductOverride::class);
    }

    /**
     * The rebate that actually applies: a customer-level rebate overrides the
     * tier's; otherwise the tier's default applies (pricing engine §5.3).
     */
    public function effectiveRebatePercent(): string
    {
        if ((float) $this->rebate_percent > 0) {
            return (string) $this->rebate_percent;
        }

        if ($this->pricing_tier_id === null) {
            return '0.00';
        }

        $tier = PricingTier::query()->whereKey($this->pricing_tier_id)->first();

        if ($tier === null) {
            return '0.00';
        }

        return (string) $tier->rebate_percent;
    }
}
