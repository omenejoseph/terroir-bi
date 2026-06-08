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
            ['slug' => 'trial', 'name' => 'Trial', 'price_minor' => 0, 'currency' => 'EUR'],
            ['slug' => 'standard', 'name' => 'Standard', 'price_minor' => 4900, 'currency' => 'EUR'],
            ['slug' => 'pro', 'name' => 'Pro', 'price_minor' => 9900, 'currency' => 'EUR'],
        ];

        foreach ($plans as $plan) {
            Plan::updateOrCreate(
                ['slug' => $plan['slug']],
                ['name' => $plan['name'], 'price_minor' => $plan['price_minor'], 'currency' => $plan['currency']],
            );
        }
    }
}
