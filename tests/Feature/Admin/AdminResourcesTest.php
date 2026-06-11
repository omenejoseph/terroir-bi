<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Actions\Tenancy\AddTenantMemberAction;
use App\Actions\Tenancy\SetPlatformAdminAction;
use App\Enums\Module;
use App\Enums\TenantRole;
use App\Filament\Resources\Plans\Pages\CreatePlan;
use App\Filament\Resources\Plans\PlanResource;
use App\Filament\Resources\PlatformAdmins\Pages\CreatePlatformAdmin;
use App\Filament\Resources\Tenants\TenantResource;
use App\Models\Plan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\InteractsWithTenancy;
use Tests\TestCase;

class AdminResourcesTest extends TestCase
{
    use InteractsWithTenancy;
    use RefreshDatabase;

    private function admin(): User
    {
        $admin = User::factory()->create();

        return app(SetPlatformAdminAction::class)->execute($admin, true);
    }

    public function test_plan_form_stores_the_price_entered_in_major_units(): void
    {
        $this->actingAs($this->admin());

        Livewire::test(CreatePlan::class)
            ->fillForm([
                'name' => 'Estate', 'slug' => 'estate', 'price_minor' => '15.00', 'currency' => 'EUR',
                'interval' => 'month', 'trial_days' => 0, 'grace_full_days' => 0, 'grace_readonly_days' => 0,
                'is_active' => true, 'is_public' => true,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $plan = Plan::query()->where('slug', 'estate')->firstOrFail();
        $this->assertSame(1500, $plan->price_minor?->getMinorAmount()); // 15.00 € → 1500 minor
    }

    public function test_add_tenant_member_provisions_a_new_user_and_membership(): void
    {
        $tenant = $this->createTenant();

        $membership = app(AddTenantMemberAction::class)->execute($tenant, [
            'first_name' => 'Mara', 'last_name' => 'Cellar', 'email' => 'mara@vino.hr',
            'password' => 'password123', 'roles' => [TenantRole::Sales->value], 'status' => 'active',
        ]);

        $this->assertDatabaseHas('users', ['email' => 'mara@vino.hr']);
        $this->assertSame($tenant->getKey(), $membership->tenant_id);
        $this->assertTrue($membership->roles->contains(TenantRole::Sales));
    }

    public function test_create_platform_admin_grants_back_office_access(): void
    {
        $this->actingAs($this->admin());

        Livewire::test(CreatePlatformAdmin::class)
            ->fillForm([
                'first_name' => 'Sam', 'last_name' => 'Ops', 'email' => 'sam@ops.io', 'password' => 'password123',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $user = User::query()->where('email', 'sam@ops.io')->firstOrFail();
        $this->assertTrue($user->is_platform_admin);
        // A fresh platform admin can sign in to the back office.
        $this->actingAs($user)->get('/admin')->assertSuccessful();
    }

    public function test_admin_pages_render(): void
    {
        $admin = $this->admin();
        $tenant = $this->createTenant();
        $plan = Plan::create(['name' => 'Pro', 'slug' => 'pro', 'currency' => 'EUR', 'modules' => Module::values()]);

        $this->actingAs($admin);
        $this->get('/admin/platform-admins')->assertSuccessful();
        $this->get('/admin/users')->assertSuccessful();
        // View pages mount the new relation managers (Plan→Tenants, Tenant→Members).
        $this->get(PlanResource::getUrl('view', ['record' => $plan]))->assertSuccessful();
        $this->get(TenantResource::getUrl('view', ['record' => $tenant]))->assertSuccessful();
    }
}
