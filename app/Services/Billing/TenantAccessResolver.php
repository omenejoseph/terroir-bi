<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\DataTransferObjects\TenantAccessData;
use App\Models\Tenant;
use App\Tenancy\Contracts\TenantContext;
use Illuminate\Support\Carbon;

/**
 * Loads a tenant's plan + subscription and runs the (pure) access service,
 * memoising the result per tenant for the duration of the request. The shared
 * seam used by both EnforceTenantAccess middleware and the SessionBuilder.
 *
 * Register as a singleton so the cache is shared across a request.
 */
class TenantAccessResolver
{
    private ?TenantAccessData $cached = null;

    private ?string $cachedTenantId = null;

    public function __construct(private readonly SubscriptionAccessService $service) {}

    /** Access for the currently bound tenant, or null if none is bound. */
    public function forCurrent(TenantContext $context): ?TenantAccessData
    {
        $tenant = $context->current();

        return $tenant === null ? null : $this->for($tenant);
    }

    public function for(Tenant $tenant): TenantAccessData
    {
        if ($this->cached !== null && $this->cachedTenantId === $tenant->getKey()) {
            return $this->cached;
        }

        $tenant->loadMissing(['plan', 'subscription']);

        $this->cached = $this->service->compute(
            $tenant,
            $tenant->subscription,
            $tenant->plan,
            Carbon::now(),
        );
        $this->cachedTenantId = $tenant->getKey();

        return $this->cached;
    }
}
