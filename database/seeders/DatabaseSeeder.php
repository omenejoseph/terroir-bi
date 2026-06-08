<?php

namespace Database\Seeders;

use App\Enums\MembershipStatus;
use App\Enums\TenantRole;
use App\Enums\TenantStatus;
use App\Models\Membership;
use App\Models\Tenant;
use App\Models\TenantSetting;
use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed a demo tenant with an admin user (global identity + membership).
     */
    public function run(): void
    {
        $tenant = Tenant::create([
            'name' => 'Demo Winery',
            'slug' => 'demo',
            'status' => TenantStatus::Active,
        ]);

        TenantSetting::create([
            'tenant_id' => $tenant->getKey(),
            'default_currency' => 'EUR',
            'default_locale' => 'hr',
        ]);

        $user = User::factory()->create([
            'first_name' => 'Test',
            'last_name' => 'User',
            'email' => 'test@example.com',
        ]);

        Membership::create([
            'tenant_id' => $tenant->getKey(),
            'user_id' => $user->getKey(),
            'roles' => collect([TenantRole::Admin]),
            'status' => MembershipStatus::Active,
            'joined_at' => now(),
        ]);
    }
}
