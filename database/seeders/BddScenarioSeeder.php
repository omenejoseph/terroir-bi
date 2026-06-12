<?php

namespace Database\Seeders;

use App\Enums\BddScenarioStatus;
use App\Models\BddScenario;
use Illuminate\Database\Seeder;

/**
 * One example scenario (ORD-001 from the Essentials scenario database) so the
 * BDD studio isn't empty on first visit. Left as DRAFT — compiling it is the
 * admin's first guided step (and requires AI to be enabled).
 */
class BddScenarioSeeder extends Seeder
{
    public function run(): void
    {
        if (BddScenario::query()->where('slug', 'ord-001-example')->exists()) {
            return;
        }

        BddScenario::create([
            'title' => 'ORD-001 — Stock is committed at order creation',
            'slug' => 'ord-001-example',
            'status' => BddScenarioStatus::Draft,
            'is_active' => true,
            'gherkin' => <<<'GHERKIN'
                Scenario: Creating an order deducts stock immediately
                  Given "R3 2025" has 100 bottles in stock
                  And a customer exists
                  When a non-backorder order for 24 bottles of "R3 2025" is created
                  Then current stock of "R3 2025" is 76 bottles
                  And an ORDER_DEDUCT movement of -24 bottles references the order number
                GHERKIN,
        ]);
    }
}
