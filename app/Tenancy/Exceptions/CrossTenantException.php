<?php

declare(strict_types=1);

namespace App\Tenancy\Exceptions;

use RuntimeException;

/**
 * Thrown when a model would be written with a tenant_id that does not match the
 * currently bound tenant — a defense-in-depth guard against cross-tenant writes.
 */
class CrossTenantException extends RuntimeException
{
    public function __construct(string $message = 'Attempted to write a record belonging to a different tenant than the bound context.')
    {
        parent::__construct($message);
    }
}
