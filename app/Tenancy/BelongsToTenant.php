<?php

declare(strict_types=1);

namespace App\Tenancy;

use App\Models\Tenant;
use App\Tenancy\Contracts\TenantContext;
use App\Tenancy\Exceptions\CrossTenantException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Applied to every tenant-owned model. Provides:
 *
 *  1. Automatic read scoping via TenantScope (fail-closed).
 *  2. Automatic tenant_id assignment on create.
 *  3. A defense-in-depth guard against saving a row for another tenant.
 *
 * Models that are NOT tenant-owned (Tenant, Plan, Domain, TenantSetting) must
 * not use this trait.
 *
 * @mixin Model
 *
 * @method static Builder<static> withoutTenant() Audited escape hatch that removes the tenant scope (TenantScope macro).
 */
trait BelongsToTenant
{
    protected static function bootBelongsToTenant(): void
    {
        static::addGlobalScope(new TenantScope);

        static::creating(function (Model $model): void {
            if (empty($model->getAttribute(TenantScope::TENANT_COLUMN))) {
                // Throws NoTenantContextException if unbound — never silently null.
                $model->setAttribute(
                    TenantScope::TENANT_COLUMN,
                    app(TenantContext::class)->id(),
                );
            }
        });

        static::saving(function (Model $model): void {
            $context = app(TenantContext::class);
            $tenantId = $model->getAttribute(TenantScope::TENANT_COLUMN);

            if ($context->check() && $tenantId !== null && $tenantId !== $context->id()) {
                throw new CrossTenantException;
            }
        });
    }

    /**
     * @return BelongsTo<Tenant, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * Seam for future mixed-mode (dedicated-DB) tenants. Today this is a no-op
     * that defers to the default connection. When a tenant is on its own
     * database, this is where the per-tenant connection name would be returned.
     */
    public function getConnectionName()
    {
        return parent::getConnectionName();
    }
}
