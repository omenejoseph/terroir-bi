<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Actions\Tenancy\AssignPlanToTenantAction;
use App\Actions\Tenancy\SetPlatformAdminAction;
use App\Actions\Tenancy\UpdateTenantStatusAction;
use App\Enums\Module;
use App\Enums\TenantStatus;
use App\Models\Plan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithTenancy;
use Tests\TestCase;

class TenantAdminActionsTest extends TestCase
{
    use InteractsWithTenancy;
    use RefreshDatabase;

    public function test_assign_plan_sets_the_modules_source(): void
    {
        $tenant = $this->createTenant();
        $plan = Plan::create(['name' => 'Estate', 'slug' => 'estate-t', 'currency' => 'EUR', 'modules' => Module::values()]);

        app(AssignPlanToTenantAction::class)->execute($tenant, $plan);

        $this->assertSame($plan->getKey(), $tenant->fresh()?->plan_id);
    }

    public function test_update_status_changes_lifecycle(): void
    {
        $tenant = $this->createTenant();

        app(UpdateTenantStatusAction::class)->execute($tenant, TenantStatus::Suspended);

        $this->assertSame(TenantStatus::Suspended, $tenant->fresh()?->status);
    }

    public function test_set_platform_admin_toggles_the_flag(): void
    {
        $user = User::factory()->create();

        $granted = app(SetPlatformAdminAction::class)->execute($user, true);
        $this->assertTrue($granted->is_platform_admin);
        $this->assertDatabaseHas('users', ['id' => $user->getKey(), 'is_platform_admin' => true]);

        app(SetPlatformAdminAction::class)->execute($user, false);
        $this->assertDatabaseHas('users', ['id' => $user->getKey(), 'is_platform_admin' => false]);
    }
}
