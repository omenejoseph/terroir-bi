<?php

declare(strict_types=1);

namespace App\Actions\Tenancy;

use App\Enums\MembershipStatus;
use App\Enums\TenantRole;
use App\Models\Membership;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Provisions a brand-new user account and grants it membership of a tenant with
 * the given roles. Used by the Filament tenant "Add member" action.
 */
class AddTenantMemberAction
{
    /**
     * @param  array<string, mixed>  $data  first_name, last_name, email, password, roles[], status
     */
    public function execute(Tenant $tenant, array $data): Membership
    {
        return DB::transaction(function () use ($tenant, $data): Membership {
            $user = User::create([
                'first_name' => (string) $data['first_name'],
                'last_name' => (string) $data['last_name'],
                'email' => (string) $data['email'],
                'password' => Hash::make((string) $data['password']),
            ]);

            $roles = collect(is_array($data['roles'] ?? null) ? $data['roles'] : [])
                ->map(fn (mixed $role): TenantRole => TenantRole::from((string) $role));

            return Membership::create([
                'tenant_id' => $tenant->getKey(),
                'user_id' => $user->getKey(),
                'roles' => $roles->isEmpty() ? collect([TenantRole::Employee]) : $roles,
                'status' => MembershipStatus::from(is_string($data['status'] ?? null) ? $data['status'] : MembershipStatus::Active->value),
                'joined_at' => now(),
            ]);
        });
    }
}
