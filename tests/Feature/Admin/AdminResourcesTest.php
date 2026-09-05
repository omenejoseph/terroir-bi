<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Actions\Tenancy\AddTenantMemberAction;
use App\Actions\Tenancy\SetPlatformAdminAction;
use App\Enums\Module;
use App\Enums\TenantRole;
use App\Enums\TenantStatus;
use App\Models\Plan;
use App\Models\Tenant;
use App\Models\TenantSubscription;
use App\Models\TranslationOverride;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\InteractsWithTenancy;
use Tests\TestCase;

/**
 * The Inertia /admin screens (app/Http/Controllers/Web/Admin/**) — the
 * replacement for the Livewire/Filament-driven version of this test now that
 * the back office is ported off Filament. Every screen still goes through
 * the same underlying Actions/Queries; only the request shape changed from
 * Livewire::test() to plain HTTP.
 */
class AdminResourcesTest extends TestCase
{
    use InteractsWithTenancy;
    use RefreshDatabase;

    private function admin(): User
    {
        $admin = User::factory()->create();

        return app(SetPlatformAdminAction::class)->execute($admin, true);
    }

    public function test_platform_admin_creates_a_global_translation_override(): void
    {
        $this->actingAs($this->admin())
            ->post('/admin/translation-overrides', [
                'locale' => 'hr', 'key' => 'orders.title', 'value' => 'Narudžbe',
            ])
            ->assertRedirect();

        $override = TranslationOverride::query()->where('key', 'orders.title')->firstOrFail();
        $this->assertSame('Narudžbe', $override->value);
        $this->assertSame('hr', $override->locale);
    }

    public function test_plan_form_stores_the_price_entered_in_major_units(): void
    {
        $this->actingAs($this->admin())
            ->post('/admin/plans', [
                'name' => 'Estate', 'slug' => 'estate', 'price_minor' => '15.00', 'currency' => 'EUR',
                'interval' => 'month', 'trial_days' => 0, 'grace_full_days' => 0, 'grace_readonly_days' => 0,
                'is_active' => true, 'is_public' => true,
            ])
            ->assertRedirect();

        $plan = Plan::query()->where('slug', 'estate')->firstOrFail();
        $this->assertSame(1500, $plan->price_minor?->getMinorAmount()); // 15.00 € → 1500 minor
    }

    public function test_create_tenant_uses_currency_and_locale_fields(): void
    {
        $this->actingAs($this->admin())
            ->post('/admin/tenants', [
                'name' => 'New Winery', 'slug' => 'new-winery',
                'admin_first_name' => 'Ana', 'admin_last_name' => 'Horvat', 'admin_email' => 'ana@nw.hr',
                'admin_password' => 'password123', 'currency' => 'USD', 'locale' => 'en',
            ])
            ->assertRedirect();

        $tenant = Tenant::query()->where('slug', 'new-winery')->firstOrFail();
        $this->assertSame('en', $tenant->default_locale);
        $this->assertSame('USD', $tenant->settings?->default_currency);
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
        $this->actingAs($this->admin())
            ->post('/admin/platform-admins', [
                'first_name' => 'Sam', 'last_name' => 'Ops', 'email' => 'sam@ops.io', 'password' => 'password123',
            ])
            ->assertRedirect();

        $user = User::query()->where('email', 'sam@ops.io')->firstOrFail();
        $this->assertTrue($user->is_platform_admin);
        // A fresh platform admin can sign in to the back office.
        $this->actingAs($user)->get('/admin')->assertSuccessful();
    }

    public function test_subscription_link_shows_only_for_unsubscribed_paid_tenants(): void
    {
        $plan = Plan::create([
            'name' => 'Pro', 'slug' => 'pro-sub', 'currency' => 'EUR',
            'modules' => Module::values(), 'stripe_price_id' => 'price_123',
        ]);

        // Paid plan, no subscription yet → the link is offered.
        $needs = $this->createTenant(['plan_id' => $plan->getKey()]);

        $this->actingAs($this->admin())
            ->get("/admin/tenants/{$needs->getKey()}")
            ->assertInertia(fn (Assert $page) => $page->where('tenant.needs_subscription', true));

        // Already subscribed → the link is hidden.
        $subscribed = $this->createTenant(['plan_id' => $plan->getKey()]);
        TenantSubscription::create([
            'tenant_id' => $subscribed->getKey(),
            'stripe_subscription_id' => 'sub_1',
            'stripe_status' => 'active',
        ]);

        $this->actingAs($this->admin())
            ->get("/admin/tenants/{$subscribed->getKey()}")
            ->assertInertia(fn (Assert $page) => $page->where('tenant.needs_subscription', false));
    }

    public function test_tenant_status_and_plan_can_be_updated_from_the_show_page(): void
    {
        $plan = Plan::create(['name' => 'Pro', 'slug' => 'pro-assign', 'currency' => 'EUR', 'modules' => Module::values()]);
        $tenant = $this->createTenant(['status' => TenantStatus::Trial]);

        $this->actingAs($this->admin())
            ->patch("/admin/tenants/{$tenant->getKey()}/status", ['status' => TenantStatus::Active->value])
            ->assertRedirect();
        $this->assertSame(TenantStatus::Active, $tenant->refresh()->status);

        $this->actingAs($this->admin())
            ->patch("/admin/tenants/{$tenant->getKey()}/plan", ['plan_id' => $plan->getKey()])
            ->assertRedirect();
        $this->assertSame($plan->getKey(), $tenant->refresh()->plan_id);
    }

    public function test_admin_pages_render(): void
    {
        $admin = $this->admin();
        $tenant = $this->createTenant();
        $plan = Plan::create(['name' => 'Pro', 'slug' => 'pro', 'currency' => 'EUR', 'modules' => Module::values()]);

        $this->actingAs($admin);
        $this->get('/admin')->assertSuccessful();
        $this->get('/admin/platform-admins')->assertSuccessful();
        $this->get('/admin/users')->assertSuccessful();
        $this->get('/admin/translation-overrides')->assertSuccessful();
        // List pages render the row actions (incl. the subscription-link dialog config).
        $this->get('/admin/plans')->assertSuccessful();
        $this->get('/admin/tenants')->assertSuccessful();
        // Show pages mount the new relation sections (Plan→Tenants, Tenant→Members).
        $this->get("/admin/plans/{$plan->getKey()}")->assertSuccessful();
        $this->get("/admin/tenants/{$tenant->getKey()}")->assertSuccessful();
        $this->get('/admin/bdd-scenarios')->assertSuccessful();
        $this->get('/admin/bdd-access')->assertSuccessful();
        $this->get('/admin/ai-settings')->assertSuccessful();
        $this->get('/admin/ai-spend')->assertSuccessful();
        $this->get('/admin/stripe-settings')->assertSuccessful();
        $this->get('/admin/broadcast')->assertSuccessful();
    }
}
