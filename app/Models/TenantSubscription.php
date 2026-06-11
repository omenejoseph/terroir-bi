<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A tenant's Stripe billing state (one-to-one with Tenant). `stripe_status`
 * stays a raw string — Stripe owns that vocabulary (trialing/active/past_due/
 * canceled/unpaid/…). The access decision is derived from these fields + the
 * plan's grace windows by App\Services\Billing\SubscriptionAccessService.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string|null $stripe_customer_id
 * @property string|null $stripe_subscription_id
 * @property string|null $stripe_status
 * @property string|null $stripe_price_id
 * @property Carbon|null $trial_ends_at
 * @property Carbon|null $current_period_end
 * @property Carbon|null $canceled_at
 * @property Carbon|null $ends_at
 */
class TenantSubscription extends Model
{
    use HasUlids;

    protected $fillable = [
        'tenant_id',
        'stripe_customer_id',
        'stripe_subscription_id',
        'stripe_status',
        'stripe_price_id',
        'trial_ends_at',
        'current_period_end',
        'canceled_at',
        'ends_at',
    ];

    protected function casts(): array
    {
        return [
            'trial_ends_at' => 'datetime',
            'current_period_end' => 'datetime',
            'canceled_at' => 'datetime',
            'ends_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Tenant, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
