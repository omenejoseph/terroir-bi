<?php

declare(strict_types=1);

namespace App\Actions\Tenancy;

use App\Enums\MembershipStatus;
use App\Enums\TenantRole;
use App\Enums\TenantStatus;
use App\Models\Membership;
use App\Models\Tenant;
use App\Models\TenantSetting;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Provisions a new tenant with its settings and a first ADMIN user. Reusable by
 * the `tenant:create` command and (later) a public signup endpoint.
 *
 * If a user with the admin email already exists, they are reused (and simply
 * gain an admin membership of the new tenant) rather than recreated.
 */
class CreateTenantAction
{
    /**
     * @param  array{
     *     name: string,
     *     slug: string,
     *     currency: string,
     *     locale: string,
     *     plan_id?: ?string,
     *     admin: array{first_name: string, middle_name?: ?string, last_name: string, email: string, password: string}
     * }  $data
     * @return array{tenant: Tenant, user: User, user_created: bool}
     */
    public function execute(array $data): array
    {
        return DB::transaction(function () use ($data): array {
            $tenant = Tenant::create([
                'name' => $data['name'],
                'slug' => $data['slug'],
                'status' => TenantStatus::Active,
                'default_locale' => $data['locale'],
                'plan_id' => $data['plan_id'] ?? null,
            ]);

            TenantSetting::create([
                'tenant_id' => $tenant->getKey(),
                'default_currency' => $data['currency'],
                'default_locale' => $data['locale'],
            ]);

            $admin = $data['admin'];
            $user = User::query()->where('email', $admin['email'])->first();
            $userCreated = false;

            if ($user === null) {
                $user = User::create([
                    'first_name' => $admin['first_name'],
                    'middle_name' => $admin['middle_name'] ?? null,
                    'last_name' => $admin['last_name'],
                    'email' => $admin['email'],
                    'password' => Hash::make($admin['password']),
                ]);
                $userCreated = true;
            }

            Membership::firstOrCreate(
                ['tenant_id' => $tenant->getKey(), 'user_id' => $user->getKey()],
                [
                    'roles' => collect([TenantRole::Admin]),
                    'status' => MembershipStatus::Active,
                    'joined_at' => now(),
                ],
            );

            return ['tenant' => $tenant, 'user' => $user, 'user_created' => $userCreated];
        });
    }
}
