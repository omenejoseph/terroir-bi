<?php

declare(strict_types=1);

namespace App\Tenancy;

use App\Tenancy\Contracts\TenantContext;
use App\Tenancy\Exceptions\NoTenantContextException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Global scope that constrains every query on a tenant-owned model to the
 * currently bound tenant.
 *
 * Fail-closed: if no tenant is bound, this throws rather than returning rows
 * for all tenants. The only sanctioned bypass is the ->withoutTenant() macro,
 * which must be reserved for audited platform / back-office operations.
 */
class TenantScope implements Scope
{
    public const TENANT_COLUMN = 'tenant_id';

    public function apply(Builder $builder, Model $model): void
    {
        $context = app(TenantContext::class);

        if (! $context->check()) {
            throw new NoTenantContextException;
        }

        $builder->where(
            $model->getTable().'.'.static::TENANT_COLUMN,
            $context->id(),
        );
    }

    public function extend(Builder $builder): void
    {
        // Audited escape hatch for platform/back-office code that must read
        // across tenants (e.g. resolving a tenant from a subdomain).
        $builder->macro('withoutTenant', function (Builder $builder) {
            return $builder->withoutGlobalScope(static::class);
        });
    }
}
