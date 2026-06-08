<?php

declare(strict_types=1);

namespace Tests\Concerns;

use App\Enums\MembershipStatus;
use App\Enums\TenantRole;
use App\Enums\TenantStatus;
use App\Models\Membership;
use App\Models\Tenant;
use App\Models\TenantSetting;
use App\Models\User;
use App\Tenancy\Contracts\TenantContext;

trait InteractsWithTenancy
{
    /**
     * @param  array<string, mixed>  $attributes
     * @param  array<string, mixed>  $settings
     */
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

        $tenant->refresh();

        return $tenant;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    protected function createUser(array $attributes = []): User
    {
        return User::factory()->create($attributes);
    }

    /**
     * Create a user with a membership of the given tenant.
     *
     * @param  list<TenantRole>  $roles
     * @param  array<string, mixed>  $attributes
     */
    protected function createMember(
        Tenant $tenant,
        array $roles = [TenantRole::Admin],
        array $attributes = [],
        MembershipStatus $status = MembershipStatus::Active,
    ): User {
        $user = $this->createUser($attributes);

        $this->createMembershipFor($user, $tenant, $roles, $status);

        return $user;
    }

    /**
     * Attach an existing user to a tenant with the given roles.
     *
     * @param  list<TenantRole>  $roles
     */
    protected function createMembershipFor(
        User $user,
        Tenant $tenant,
        array $roles = [TenantRole::Admin],
        MembershipStatus $status = MembershipStatus::Active,
    ): Membership {
        return Membership::create([
            'tenant_id' => $tenant->getKey(),
            'user_id' => $user->getKey(),
            'roles' => collect($roles),
            'status' => $status,
            'joined_at' => now(),
        ]);
    }

    /** Bind a tenant into the context directly (for non-HTTP service tests). */
    protected function actingAsTenant(Tenant $tenant): Tenant
    {
        app(TenantContext::class)->makeCurrent($tenant);

        return $tenant;
    }

    protected function forgetTenant(): void
    {
        app(TenantContext::class)->forget();
    }

    /**
     * Headers that select the active tenant over HTTP (membership still verified).
     *
     * @return array<string, string>
     */
    protected function tenantHeader(Tenant $tenant): array
    {
        return ['X-Tenant' => $tenant->getKey()];
    }
}
