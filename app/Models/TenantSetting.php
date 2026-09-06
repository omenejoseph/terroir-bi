<?php

declare(strict_types=1);

namespace App\Models;

use App\Services\Audit\Auditable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Per-tenant, non-secret configuration (1:1 with Tenant). Accessed via the
 * tenant relation, so it is not itself tenant-scoped.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $default_currency
 * @property string $default_locale
 * @property string $timezone
 * @property string|null $company_oib
 * @property string|null $storage_prefix
 * @property int|null $annual_revenue_target
 * @property array<string, int>|null $channel_revenue_targets
 * @property int|null $cash_on_hand
 * @property Carbon|null $cash_on_hand_as_of
 */
class TenantSetting extends Model
{
    use Auditable;
    use HasUlids;

    protected $fillable = [
        'tenant_id',
        'default_currency',
        'default_locale',
        'timezone',
        'company_oib',
        'storage_prefix',
        'annual_revenue_target',
        'channel_revenue_targets',
        'cash_on_hand',
        'cash_on_hand_as_of',
    ];

    protected $attributes = [
        'default_currency' => 'EUR',
        'default_locale' => 'hr',
        'timezone' => 'Europe/Zagreb',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'channel_revenue_targets' => 'array',
            'cash_on_hand_as_of' => 'date',
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
