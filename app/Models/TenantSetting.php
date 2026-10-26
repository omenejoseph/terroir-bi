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
 * @property string|null $address
 * @property string|null $city
 * @property string|null $zip
 * @property string|null $country
 * @property string|null $contact_email
 * @property string|null $contact_phone
 * @property string|null $bank_name
 * @property string|null $iban
 * @property string|null $storage_prefix
 * @property int|null $annual_revenue_target
 * @property array<string, int>|null $channel_revenue_targets
 * @property int|null $cash_on_hand
 * @property Carbon|null $cash_on_hand_as_of
 * @property string $revenue_basis
 * @property array{skipped?: list<string>, done?: list<string>, dismissed_at?: string|null, answers?: array<string, mixed>}|null $onboarding
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
        'address',
        'city',
        'zip',
        'country',
        'contact_email',
        'contact_phone',
        'bank_name',
        'iban',
        'storage_prefix',
        'annual_revenue_target',
        'channel_revenue_targets',
        'cash_on_hand',
        'cash_on_hand_as_of',
        'revenue_basis',
        'onboarding',
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
            'onboarding' => 'array',
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
