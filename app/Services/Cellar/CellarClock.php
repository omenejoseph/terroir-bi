<?php

declare(strict_types=1);

namespace App\Services\Cellar;

use App\Models\TenantSetting;
use App\Tenancy\Contracts\TenantContext;
use Carbon\CarbonImmutable;

/**
 * "Today" for cellar planning, in the tenant's own timezone. Planned work is
 * dated (no time), so what counts as today/overdue must follow the winery's
 * clock, not the server's — the prototype mixed UTC and server-local here.
 */
class CellarClock
{
    public function __construct(private readonly TenantContext $tenants) {}

    public function timezone(): string
    {
        $tz = $this->tenants->check()
            ? TenantSetting::query()->where('tenant_id', $this->tenants->id())->value('timezone')
            : null;

        return is_string($tz) && $tz !== '' ? $tz : (string) config('app.timezone');
    }

    public function now(): CarbonImmutable
    {
        return CarbonImmutable::now($this->timezone());
    }

    public function today(): string
    {
        return $this->now()->toDateString();
    }
}
