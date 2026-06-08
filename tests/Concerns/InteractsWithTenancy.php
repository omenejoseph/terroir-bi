<?php

declare(strict_types=1);

namespace Tests\Concerns;

use App\Enums\TenantStatus;
use App\Models\Tenant;
use App\Models\TenantSetting;
use App\Tenancy\Contracts\TenantContext;

trait InteractsWithTenancy
{
    protected function createTenant(array $attributes = [], array $settings = []): Tenant
    {
        $tenant = Tenant::create(array_merge([
            'name' => 'Tenant '.fake()->unique()->word(),
            'slug' => fake()->unique()->slug(2),
            'status' => TenantStatus::Active,
        ], $attributes));

        TenantSetting::create(array_merge([
            'tenant_id' => $tenant->getKey(),
            'default_currency' => 'EUR',
            'default_locale' => 'hr',
        ], $settings));

        return $tenant->fresh();
    }

    protected function actingAsTenant(Tenant $tenant): Tenant
    {
        app(TenantContext::class)->makeCurrent($tenant);

        return $tenant;
    }

    protected function forgetTenant(): void
    {
        app(TenantContext::class)->forget();
    }
}
