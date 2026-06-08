<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Per-tenant, non-secret configuration (1:1 with Tenant). Accessed via the
 * tenant relation, so it is not itself tenant-scoped.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $default_currency
 * @property string $default_locale
 * @property string|null $company_oib
 * @property string|null $storage_prefix
 */
class TenantSetting extends Model
{
    use HasUlids;

    protected $fillable = [
        'tenant_id',
        'default_currency',
        'default_locale',
        'company_oib',
        'storage_prefix',
    ];

    protected $attributes = [
        'default_currency' => 'EUR',
        'default_locale' => 'hr',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
