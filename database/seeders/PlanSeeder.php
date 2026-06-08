<?php

namespace Database\Seeders;

use App\Models\Plan;
use Illuminate\Database\Seeder;

/**
 * Baseline subscription plans the app expects to exist. Idempotent: safe to run
 * repeatedly (keyed by slug).
 */
class PlanSeeder extends Seeder
{
    public function run(): void
    {
        $plans = [
            ['slug' => 'basic', 'name' => 'Basic', 'price_minor' => 100, 'currency' => 'EUR'],
            ['slug' => 'enterprise', 'name' => 'Enterprise', 'price_minor' => 400, 'currency' => 'EUR'],
            ['slug' => 'estate', 'name' => 'Estate', 'price_minor' => 799, 'currency' => 'EUR'],
        ];

        foreach ($plans as $plan) {
            Plan::updateOrCreate(
                ['slug' => $plan['slug']],
                ['name' => $plan['name'], 'price_minor' => $plan['price_minor'], 'currency' => $plan['currency']],
            );
        }
    }
}
