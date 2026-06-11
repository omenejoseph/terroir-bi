<?php

declare(strict_types=1);

namespace App\Services\Suppliers;

use App\Models\Supplier;
use App\Models\Tenant;
use App\Services\Orders\PublicTokenResolver;
use App\Tenancy\Contracts\TenantContext;

/**
 * Resolves a supplier portal token to its supplier and binds the tenant context
 * (the token is the credential AND the tenant selector — one lookup binds both).
 * Mirrors {@see PublicTokenResolver}.
 */
class SupplierTokenResolver
{
    public function __construct(private readonly TenantContext $context) {}

    /**
     * Look up the active supplier for a token and make its tenant current.
     * Returns null when the token is unknown or the supplier is inactive.
     */
    public function resolve(string $token): ?Supplier
    {
        $supplier = Supplier::withoutTenant()
            ->where('portal_token', $token)
            ->where('is_active', true)
            ->first();

        if (! $supplier instanceof Supplier) {
            return null;
        }

        $tenant = Tenant::query()->find($supplier->tenant_id);

        if (! $tenant instanceof Tenant) {
            return null;
        }

        $this->context->makeCurrent($tenant);

        return $supplier;
    }
}
