<?php

declare(strict_types=1);

namespace App\Tenancy;

use App\Models\Tenant;
use App\Tenancy\Adapters\Stancl\StanclTenantAdapter;
use App\Tenancy\Contracts\TenantContext;
use App\Tenancy\Exceptions\NoTenantContextException;
use Closure;
use Illuminate\Contracts\Container\Container;

/**
 * The single implementation of TenantContext that application code uses.
 *
 * The current tenant lives in a request-scoped container binding ('tenant').
 * In shared_row mode that binding is all that's needed: TenantScope reads it.
 * For dedicated_db ("mixed mode") tenants, makeCurrent() additionally delegates
 * to the driver adapter to switch the database connection — that path is gated
 * on isolation_mode and currently throws (not yet enabled).
 */
class TenantManager implements TenantContext
{
    private const BINDING = 'tenant';

    public function __construct(
        private readonly Container $app,
        private readonly StanclTenantAdapter $adapter,
    ) {}

    public function current(): ?Tenant
    {
        return $this->app->bound(self::BINDING)
            ? $this->app->make(self::BINDING)
            : null;
    }

    public function currentId(): ?string
    {
        return $this->current()?->getKey();
    }

    public function check(): bool
    {
        return $this->app->bound(self::BINDING);
    }

    public function id(): string
    {
        return $this->currentId() ?? throw new NoTenantContextException;
    }

    public function makeCurrent(Tenant $tenant): void
    {
        // 1. Row-mode binding — always. This is what TenantScope reads.
        $this->app->instance(self::BINDING, $tenant);

        // 2. Dedicated-DB tenants additionally switch connection (future).
        if ($tenant->isolation_mode === Tenant::ISOLATION_DEDICATED_DB) {
            $this->adapter->initializeDatabase($tenant);
        }
    }

    public function forget(): void
    {
        if ($this->check()) {
            $current = $this->current();

            if ($current !== null && $current->isolation_mode === Tenant::ISOLATION_DEDICATED_DB) {
                $this->adapter->endDatabase();
            }

            $this->app->forgetInstance(self::BINDING);
        }
    }

    public function runFor(Tenant $tenant, Closure $callback): mixed
    {
        $previous = $this->current();

        $this->makeCurrent($tenant);

        try {
            return $callback($tenant);
        } finally {
            if ($previous !== null) {
                $this->makeCurrent($previous);
            } else {
                $this->forget();
            }
        }
    }
}
