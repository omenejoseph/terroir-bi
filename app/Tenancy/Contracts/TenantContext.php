<?php

declare(strict_types=1);

namespace App\Tenancy\Contracts;

use App\Models\Tenant;
use App\Tenancy\Exceptions\NoTenantContextException;
use Closure;

/**
 * The current-tenant context for a request, job, or test.
 *
 * This is the primary seam over the underlying tenancy driver. Application code
 * (models, scopes, services) depends only on this contract — never on stancl.
 */
interface TenantContext
{
    /** The currently bound tenant, or null if none is bound. */
    public function current(): ?Tenant;

    /** The current tenant id, or null if none is bound. */
    public function currentId(): ?string;

    /** Whether a tenant is currently bound. */
    public function check(): bool;

    /**
     * The current tenant id, or throw if none is bound (fail-closed).
     *
     * @throws NoTenantContextException
     */
    public function id(): string;

    /** Bind the given tenant as the current tenant for this context. */
    public function makeCurrent(Tenant $tenant): void;

    /** Clear the current tenant binding (e.g. at job boundaries / in tests). */
    public function forget(): void;

    /**
     * Run a callback with the given tenant bound, then restore the previous
     * context (whether that was another tenant or no tenant at all).
     */
    public function runFor(Tenant $tenant, Closure $callback): mixed;
}
