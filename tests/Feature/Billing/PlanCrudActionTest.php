<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Actions\Billing\CreatePlanAction;
use App\Actions\Billing\DeletePlanAction;
use App\Actions\Billing\UpdatePlanAction;
use App\Enums\Module;
use App\Models\Plan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlanCrudActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_create_filters_unknown_module_keys(): void
    {
        $plan = app(CreatePlanAction::class)->execute([
            'name' => 'Starter', 'slug' => 'starter', 'currency' => 'EUR',
            'modules' => ['inventory', 'orders', 'not_a_module'],
        ]);

        $this->assertSame(['inventory', 'orders'], $plan->moduleKeys());
    }

    public function test_update_replaces_modules(): void
    {
        $plan = Plan::create(['name' => 'P', 'slug' => 'p', 'currency' => 'EUR', 'modules' => [Module::Inventory->value]]);

        app(UpdatePlanAction::class)->execute($plan, ['modules' => [Module::Costs->value, 'bogus']]);

        $this->assertSame(['costs'], $plan->fresh()?->moduleKeys());
    }

    public function test_delete_removes_the_plan_and_nulls_tenant_fk(): void
    {
        $plan = Plan::create(['name' => 'P', 'slug' => 'p2', 'currency' => 'EUR', 'modules' => []]);

        app(DeletePlanAction::class)->execute($plan);

        $this->assertSame(0, Plan::query()->where('slug', 'p2')->count());
    }
}
