<?php

declare(strict_types=1);

namespace App\Services\Orders;

use App\Enums\TenantRole;
use App\Models\Customer;
use App\Models\Membership;
use App\Models\Tenant;
use App\Tenancy\Contracts\TenantContext;

/**
 * Resolves a self-service order token to its customer, binds the tenant context
 * (the token is the credential AND the tenant selector — one lookup binds both),
 * and finds a system user to attribute customer-placed orders to.
 */
class PublicTokenResolver
{
    public function __construct(private readonly TenantContext $context) {}

    /**
     * Look up the active customer for a token and make its tenant current.
     * Returns null when the token is unknown or the customer is inactive.
     */
    public function resolve(string $token): ?Customer
    {
        $customer = Customer::withoutTenant()
            ->where('order_token', $token)
            ->where('is_active', true)
            ->first();

        if (! $customer instanceof Customer) {
            return null;
        }

        $tenant = Tenant::query()->find($customer->tenant_id);

        if (! $tenant instanceof Tenant) {
            return null;
        }

        $this->context->makeCurrent($tenant);

        return $customer;
    }

    /**
     * The user a customer-placed order is attributed to: an ADMIN of the tenant,
     * else any member. Audit trail is preserved without trusting the customer.
     */
    public function systemUserId(Customer $customer): ?string
    {
        $memberships = Membership::query()->where('tenant_id', $customer->tenant_id)->get();

        $admin = $memberships->first(fn (Membership $m) => $m->hasRole(TenantRole::Admin));

        return ($admin ?? $memberships->first())?->user_id;
    }
}
