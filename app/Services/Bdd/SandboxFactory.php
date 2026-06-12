<?php

declare(strict_types=1);

namespace App\Services\Bdd;

use App\Enums\MembershipStatus;
use App\Enums\TenantRole;
use App\Enums\TenantStatus;
use App\Models\Membership;
use App\Models\Tenant;
use App\Models\TenantSetting;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * Builds the throwaway sandbox a BDD run lives in: a fresh tenant + an admin
 * operator, created INSIDE the run's always-rolled-back transaction so both
 * vanish when the run ends (the leak check verifies that they did).
 */
class SandboxFactory
{
    public function create(): SandboxContext
    {
        $suffix = strtolower((string) Str::ulid());

        $tenant = Tenant::create([
            'name' => 'BDD Sandbox',
            'slug' => 'bdd-sandbox-'.$suffix,
            'status' => TenantStatus::Active,
        ]);

        TenantSetting::create([
            'tenant_id' => $tenant->getKey(),
            'default_currency' => 'EUR',
            'default_locale' => 'hr',
        ]);

        $admin = User::create([
            'first_name' => 'BDD',
            'last_name' => 'Runner',
            'email' => 'bdd-runner-'.$suffix.'@sandbox.test',
            'password' => Str::random(40),
        ]);

        Membership::create([
            'tenant_id' => $tenant->getKey(),
            'user_id' => $admin->getKey(),
            'roles' => collect([TenantRole::Admin]),
            'status' => MembershipStatus::Active,
            'joined_at' => now(),
        ]);

        return new SandboxContext($tenant, $admin);
    }
}
