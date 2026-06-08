<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\TenantStatus;
use App\Tenancy\Adapters\Stancl\StanclTenantModelTrait;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Stancl\Tenancy\Contracts\Tenant as StanclTenantContract;

/**
 * An organisation / account. NOT tenant-scoped (it is the tenant). Implements
 * the stancl contract via StanclTenantModelTrait so the driver can resolve and,
 * in future, bootstrap it — but application code treats it as a plain model.
 *
 * @property string $id
 * @property string $name
 * @property string $slug
 * @property TenantStatus $status
 * @property string $isolation_mode
 * @property string|null $plan_id
 * @property string $default_locale
 * @property array<string, mixed>|null $data
 */
class Tenant extends Model implements StanclTenantContract
{
    use HasUlids;
    use StanclTenantModelTrait;

    /** Shared database, row-level isolation via tenant_id (current default). */
    public const ISOLATION_SHARED_ROW = 'shared_row';

    /** Tenant has its own database ("mixed mode" — architected, not yet enabled). */
    public const ISOLATION_DEDICATED_DB = 'dedicated_db';

    protected $fillable = [
        'name',
        'slug',
        'status',
        'isolation_mode',
        'plan_id',
        'default_locale',
        'data',
    ];

    protected function casts(): array
    {
        return [
            'status' => TenantStatus::class,
            'data' => 'array',
        ];
    }

    protected $attributes = [
        'status' => TenantStatus::Trial->value,
        'isolation_mode' => self::ISOLATION_SHARED_ROW,
        'default_locale' => 'hr',
    ];

    /**
     * @return HasOne<TenantSetting, $this>
     */
    public function settings(): HasOne
    {
        return $this->hasOne(TenantSetting::class);
    }

    /**
     * @return HasMany<User, $this>
     */
    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    /**
     * @return HasMany<TranslationOverride, $this>
     */
    public function translationOverrides(): HasMany
    {
        return $this->hasMany(TranslationOverride::class);
    }

    /**
     * @return BelongsTo<Plan, $this>
     */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }
}
