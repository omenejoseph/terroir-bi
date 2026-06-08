<?php

declare(strict_types=1);

namespace App\Tenancy\Exceptions;

use RuntimeException;

/**
 * Thrown when a tenant-scoped operation runs without a bound tenant.
 *
 * This is the fail-closed guarantee: the system must never fall back to
 * returning or writing all tenants' rows when no tenant is bound.
 */
class NoTenantContextException extends RuntimeException
{
    public function __construct(string $message = 'No tenant context is bound. Tenant-scoped operations are not allowed outside a tenant context.')
    {
        parent::__construct($message);
    }
}
