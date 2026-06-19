<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed application defaults plus a fully-populated demo tenant for local dev.
     * Idempotent: safe to re-run (DemoSeeder skips if the demo tenant exists).
     */
    public function run(): void
    {
        // App-wide defaults required for the app to function.
        $this->call(PlanSeeder::class);
        $this->call(BddScenarioSeeder::class);

        // Rich demo tenant (BIBICh-style winery) with images uploaded to the bucket.
        $this->call(DemoSeeder::class);
    }
}
