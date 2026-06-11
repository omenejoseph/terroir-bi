<?php

namespace Database\Seeders;

use App\Enums\Module;
use App\Models\Plan;
use Illuminate\Database\Seeder;

/**
 * Baseline subscription plans the app expects to exist. Idempotent: safe to run
 * repeatedly (keyed by slug). Module sets form a gradient — Estate unlocks the
 * finance suite (money in / costs / cash flow).
 */
class PlanSeeder extends Seeder
{
    public function run(): void
    {
        $core = [
            Module::Dashboard->value,
            Module::Inventory->value,
            Module::Orders->value,
            Module::Customers->value,
            Module::Team->value,
            Module::Settings->value,
        ];
        $enterprise = [...$core, Module::Suppliers->value, Module::WorkOrders->value];

        $plans = [
            ['slug' => 'basic', 'name' => 'Basic', 'price_minor' => 100, 'modules' => $core],
            ['slug' => 'enterprise', 'name' => 'Enterprise', 'price_minor' => 400, 'modules' => $enterprise],
            ['slug' => 'estate', 'name' => 'Estate', 'price_minor' => 799, 'modules' => Module::values()],
        ];

        foreach ($plans as $plan) {
            Plan::updateOrCreate(
                ['slug' => $plan['slug']],
                [
                    'name' => $plan['name'],
                    'price_minor' => $plan['price_minor'],
                    'currency' => 'EUR',
                    'modules' => $plan['modules'],
                    'trial_days' => 14,
                    'grace_full_days' => 7,
                    'grace_readonly_days' => 7,
                    'interval' => 'month',
                    'is_active' => true,
                    'is_public' => true,
                    // stripe_price_id is wired per-environment from the back office.
                ],
            );
        }
    }
}
