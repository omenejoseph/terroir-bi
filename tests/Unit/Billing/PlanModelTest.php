<?php

declare(strict_types=1);

namespace Tests\Unit\Billing;

use App\Enums\Module;
use App\Models\Plan;
use Tests\TestCase;

class PlanModelTest extends TestCase
{
    public function test_modules_cast_maps_to_enum_and_drops_unknown_keys(): void
    {
        $plan = new Plan(['modules' => ['inventory', 'costs', 'bogus_key']]);

        $this->assertSame([Module::Inventory, Module::Costs], $plan->modules());
        $this->assertSame(['inventory', 'costs'], $plan->moduleKeys());
    }

    public function test_has_module(): void
    {
        $plan = new Plan(['modules' => ['inventory']]);

        $this->assertTrue($plan->hasModule(Module::Inventory));
        $this->assertFalse($plan->hasModule(Module::Costs));
    }

    public function test_is_free_when_no_stripe_price(): void
    {
        $this->assertTrue((new Plan(['modules' => []]))->isFree());
        $this->assertFalse((new Plan(['stripe_price_id' => 'price_123']))->isFree());
    }

    public function test_null_modules_is_safe(): void
    {
        $plan = new Plan;

        $this->assertSame([], $plan->modules());
        $this->assertSame([], $plan->moduleKeys());
        $this->assertFalse($plan->hasModule(Module::Inventory));
    }
}
