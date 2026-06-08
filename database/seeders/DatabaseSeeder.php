<?php

namespace Database\Seeders;

use App\Actions\Tenancy\CreateTenantAction;
use App\Models\Tenant;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed application defaults plus a demo tenant + admin for local dev.
     * Idempotent: safe to re-run.
     */
    public function run(): void
    {
        // App-wide defaults required for the app to function.
        $this->call(PlanSeeder::class);

        // Demo tenant for local development (skipped if it already exists).
        if (! Tenant::query()->where('slug', 'demo')->exists()) {
            app(CreateTenantAction::class)->execute([
                'name' => 'Demo Winery',
                'slug' => 'demo',
                'currency' => 'EUR',
                'locale' => 'hr',
                'admin' => [
                    'first_name' => 'Test',
                    'last_name' => 'User',
                    'email' => 'test@example.com',
                    'password' => 'password',
                ],
            ]);
        }
    }
}
