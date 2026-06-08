<?php

namespace Database\Seeders;

use App\Enums\TenantStatus;
use App\Models\Tenant;
use App\Models\TenantSetting;
use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database with one demo tenant and an admin user.
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

        User::factory()->forTenant($tenant)->create([
            'name' => 'Test User',
            'email' => 'test@example.com',
        ]);
    }
}
