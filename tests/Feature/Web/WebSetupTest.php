<?php

declare(strict_types=1);

namespace Tests\Feature\Web;

use App\Enums\TenantRole;
use App\Models\Customer;
use App\Models\InventoryItem;
use App\Models\Plan;
use App\Models\PricingTier;
use App\Models\ProductCategory;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Auth\ActiveTenantSession;
use App\Support\Money\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;
use Symfony\Component\HttpFoundation\Response;
use Tests\Concerns\InteractsWithTenancy;
use Tests\TestCase;

/**
 * The new-account setup hub (/setup) and its dashboard reminder — see
 * App\Services\Onboarding\OnboardingStatus. Step status is read off real
 * data, so these tests create that data and check the checklist follows.
 */
class WebSetupTest extends TestCase
{
    use InteractsWithTenancy;
    use RefreshDatabase;

    /**
     * @return array{0: Tenant, 1: User}
     */
    private function tenantAndAdmin(): array
    {
        $tenant = $this->createTenant(['name' => 'Vinarija Test']);
        $admin = $this->createMember($tenant, [TenantRole::Admin]);

        return [$tenant, $admin];
    }

    /**
     * @return TestResponse<Response>
     */
    private function hub(Tenant $tenant, User $user): TestResponse
    {
        return $this->actingAs($user)
            ->withSession([ActiveTenantSession::KEY => $tenant->getKey()])
            ->get('/setup');
    }

    /**
     * @param  TestResponse<Response>  $response
     * @return array<string, array<string, mixed>>
     */
    private function stepsByKey(TestResponse $response): array
    {
        /** @var list<array<string, mixed>> $steps */
        $steps = $response->viewData('page')['props']['steps'];

        return array_column($steps, null, 'key');
    }

    public function test_hub_lists_every_go_live_step_for_an_empty_tenant(): void
    {
        [$tenant, $admin] = $this->tenantAndAdmin();

        $this->hub($tenant, $admin)
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Setup/Index')
                ->has('steps', 7)
                ->where('steps.0.key', 'company_profile')
                ->where('steps.0.status', 'not_started')
                ->where('steps.6.key', 'team')
                ->where('steps.6.status', 'not_started')
                ->where('summary.done', 0)
                ->where('summary.total', 7)
                ->where('summary.complete', false));
    }

    public function test_hub_is_closed_to_a_non_admin(): void
    {
        $tenant = $this->createTenant();
        $member = $this->createMember($tenant, [TenantRole::Team]);

        $this->hub($tenant, $member)->assertForbidden();
    }

    public function test_steps_follow_the_tenants_data(): void
    {
        [$tenant, $admin] = $this->tenantAndAdmin();

        $this->actingAsTenant($tenant);
        $tenant->settings?->update([
            'company_oib' => '12345678901',
            'address' => 'Vinogradska 1',
            'city' => 'Zagreb',
            'country' => 'Hrvatska',
            'contact_email' => 'info@vinarija.test',
        ]);
        ProductCategory::create(['type' => 'FINISHED', 'name' => 'Red']);
        InventoryItem::create([
            'name' => 'Plavac 2022', 'sku' => 'PLV-22', 'category' => 'FINISHED', 'unit' => 'bottles',
            'is_for_sale' => true, 'default_price' => Money::fromMinor(1800, 'EUR'), 'current_stock' => '120',
        ]);
        InventoryItem::create([
            'name' => 'Malvazija 2023', 'sku' => 'MLV-23', 'category' => 'FINISHED', 'unit' => 'bottles',
            'is_for_sale' => true, 'current_stock' => '0',
        ]);
        $tier = PricingTier::create(['name' => 'Wholesale', 'rebate_percent' => '15']);
        Customer::create(['company_name' => 'Bistro', 'email' => 'bistro@example.test', 'pricing_tier_id' => $tier->getKey()]);
        Customer::create(['company_name' => 'Shop', 'email' => 'shop@example.test']);
        $this->forgetTenant();

        $steps = $this->stepsByKey($this->hub($tenant, $admin)->assertOk());

        $this->assertSame('done', $steps['company_profile']['status']);
        // All required fields are in, but no IBAN — a nudge, not a blocker.
        $this->assertCount(1, $steps['company_profile']['issues']);

        $this->assertSame('done', $steps['product_categories']['status']);

        $this->assertSame('done', $steps['finished_products']['status']);
        $this->assertSame(['done' => 1, 'total' => 2], $steps['finished_products']['progress']);
        $this->assertSame(1, $steps['finished_products']['issues'][0]['count']);

        // One of two for-sale wines has stock: in progress until confirmed.
        $this->assertSame('in_progress', $steps['opening_stock']['status']);

        $this->assertSame('done', $steps['pricing_tiers']['status']);
        $this->assertSame('done', $steps['customers']['status']);
        $this->assertSame(1, $steps['customers']['issues'][0]['count']);

        $this->assertSame('not_started', $steps['team']['status']);
    }

    public function test_an_optional_step_can_be_skipped_and_unskipped(): void
    {
        [$tenant, $admin] = $this->tenantAndAdmin();

        $this->actingAs($admin)
            ->withSession([ActiveTenantSession::KEY => $tenant->getKey()])
            ->patch('/setup/steps/pricing_tiers', ['skipped' => true])
            ->assertRedirect();

        $this->assertSame('skipped', $this->stepsByKey($this->hub($tenant, $admin))['pricing_tiers']['status']);

        $this->actingAs($admin)
            ->withSession([ActiveTenantSession::KEY => $tenant->getKey()])
            ->patch('/setup/steps/pricing_tiers', ['skipped' => false])
            ->assertRedirect();

        $this->assertSame('not_started', $this->stepsByKey($this->hub($tenant, $admin))['pricing_tiers']['status']);
    }

    public function test_a_required_step_cannot_be_skipped(): void
    {
        [$tenant, $admin] = $this->tenantAndAdmin();

        $this->actingAs($admin)
            ->withSession([ActiveTenantSession::KEY => $tenant->getKey()])
            ->from('/setup')
            ->patch('/setup/steps/customers', ['skipped' => true])
            ->assertSessionHasErrors('skipped');

        $this->assertSame('not_started', $this->stepsByKey($this->hub($tenant, $admin))['customers']['status']);
    }

    public function test_opening_stock_can_be_marked_done_by_hand(): void
    {
        [$tenant, $admin] = $this->tenantAndAdmin();

        $this->actingAs($admin)
            ->withSession([ActiveTenantSession::KEY => $tenant->getKey()])
            ->patch('/setup/steps/opening_stock', ['done' => true])
            ->assertRedirect();

        $this->assertSame('done', $this->stepsByKey($this->hub($tenant, $admin))['opening_stock']['status']);
    }

    public function test_an_unknown_step_is_not_found(): void
    {
        [$tenant, $admin] = $this->tenantAndAdmin();

        $this->actingAs($admin)
            ->withSession([ActiveTenantSession::KEY => $tenant->getKey()])
            ->patch('/setup/steps/nope', ['skipped' => true])
            ->assertNotFound();
    }

    public function test_steps_outside_the_plan_are_left_out(): void
    {
        $plan = Plan::create(['name' => 'Inventory only', 'slug' => 'inventory-only', 'modules' => ['dashboard', 'inventory', 'settings']]);
        $tenant = $this->createTenant(['plan_id' => $plan->getKey()]);
        $admin = $this->createMember($tenant, [TenantRole::Admin]);

        $steps = $this->stepsByKey($this->hub($tenant, $admin)->assertOk());

        $this->assertArrayNotHasKey('pricing_tiers', $steps);
        $this->assertArrayNotHasKey('customers', $steps);
        $this->assertArrayHasKey('finished_products', $steps);
    }

    public function test_dashboard_shows_the_reminder_until_it_is_dismissed(): void
    {
        [$tenant, $admin] = $this->tenantAndAdmin();

        $this->actingAs($admin)
            ->withSession([ActiveTenantSession::KEY => $tenant->getKey()])
            ->get('/dashboard')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('onboarding.total', 7)
                ->where('onboarding.complete', false));

        $this->actingAs($admin)
            ->withSession([ActiveTenantSession::KEY => $tenant->getKey()])
            ->post('/setup/dismiss', ['dismissed' => true])
            ->assertRedirect();

        $this->actingAs($admin)
            ->withSession([ActiveTenantSession::KEY => $tenant->getKey()])
            ->get('/dashboard')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('onboarding', null));
    }

    public function test_dashboard_has_no_reminder_for_a_member_who_cannot_use_setup(): void
    {
        $tenant = $this->createTenant();
        $member = $this->createMember($tenant, [TenantRole::Team]);

        $this->actingAs($member)
            ->withSession([ActiveTenantSession::KEY => $tenant->getKey()])
            ->get('/dashboard')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('onboarding', null));
    }

    public function test_settings_store_the_company_profile_and_keep_it_when_omitted(): void
    {
        [$tenant, $admin] = $this->tenantAndAdmin();

        $base = ['name' => 'Vinarija Test', 'default_locale' => 'hr', 'timezone' => 'Europe/Zagreb'];

        $this->actingAs($admin)
            ->withSession([ActiveTenantSession::KEY => $tenant->getKey()])
            ->patch('/settings', $base + ['address' => ' Vinogradska 1 ', 'iban' => 'HR1210010051863000160', 'city' => ''])
            ->assertRedirect();

        $this->assertDatabaseHas('tenant_settings', [
            'tenant_id' => $tenant->getKey(),
            'address' => 'Vinogradska 1',
            'iban' => 'HR1210010051863000160',
            'city' => null,
        ]);

        // A caller that doesn't know the profile fields (an older API client) leaves them alone.
        $this->actingAs($admin)
            ->withSession([ActiveTenantSession::KEY => $tenant->getKey()])
            ->patch('/settings', $base)
            ->assertRedirect();

        $this->assertDatabaseHas('tenant_settings', [
            'tenant_id' => $tenant->getKey(),
            'address' => 'Vinogradska 1',
        ]);
    }
}
