<?php

declare(strict_types=1);

namespace App\Tenancy\Adapters\Stancl;

use App\Tenancy\Contracts\TenantContext;

/**
 * Makes our App\Models\Tenant satisfy Stancl\Tenancy\Contracts\Tenant without
 * pulling stancl's VirtualColumn/data-column machinery (which would treat our
 * real columns as virtual JSON). Internal keys are stored in the model's `data`
 * JSON column.
 *
 * This trait is the ONLY tenancy-driver coupling on the Tenant model; keeping it
 * here (under Adapters/Stancl) means a future swap to another tenancy library
 * only touches this directory.
 */
trait StanclTenantModelTrait
{
    /** The column used to identify the tenant. */
    public function getTenantKeyName(): string
    {
        return $this->getKeyName();
    }

    /** The tenant's identifying value. */
    public function getTenantKey()
    {
        return $this->getKey();
    }

    /** Read an internal/virtual key from the `data` JSON column. */
    public function getInternal(string $key)
    {
        return data_get($this->data ?? [], $key);
    }

    /** Write an internal/virtual key into the `data` JSON column. */
    public function setInternal(string $key, $value)
    {
        $data = $this->data ?? [];
        $data[$key] = $value;
        $this->data = $data;

        return $this;
    }

    /**
     * Run a callback in this tenant's context, restoring the previous context
     * afterwards. Delegates to our own TenantContext so behaviour is identical
     * regardless of the underlying driver.
     */
    public function run(callable $callback)
    {
        return app(TenantContext::class)->runFor($this, fn () => $callback($this));
    }
}
