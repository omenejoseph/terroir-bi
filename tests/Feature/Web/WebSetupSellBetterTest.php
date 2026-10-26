<?php

declare(strict_types=1);

namespace Tests\Feature\Web;

use App\Enums\TenantRole;
use App\Models\Customer;
use App\Models\CustomerCategory;
use App\Models\InventoryItem;
use App\Models\RecipeItem;
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
 * Setup phase 3 — the checklist's "Sell better" section (costs, special
 * prices, customer categories, order links, reorder points, materials, a
 * first order). See App\Services\Onboarding\OnboardingStatus::SELL_BETTER.
 */
class WebSetupSellBetterTest extends TestCase
{
    use InteractsWithTenancy;
    use RefreshDatabase;

    /**
     * @return array{0: Tenant, 1: User}
     */
    private function tenantAndAdmin(): array
    {
        $tenant = $this->createTenant();
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

    public function test_sell_better_steps_follow_the_tenants_data(): void
    {
        [$tenant, $admin] = $this->tenantAndAdmin();

        $this->actingAsTenant($tenant);
        InventoryItem::create([
            'name' => 'Plavac', 'sku' => 'PLV', 'category' => 'FINISHED', 'unit' => 'bottles', 'is_for_sale' => true,
            'default_price' => Money::fromMinor(1800, 'EUR'), 'cost_per_unit' => Money::fromMinor(600, 'EUR'), 'min_stock' => '24',
        ]);
        $recipeOnly = InventoryItem::create([
            'name' => 'Malvazija', 'sku' => 'MLV', 'category' => 'FINISHED', 'unit' => 'bottles', 'is_for_sale' => true,
            'default_price' => Money::fromMinor(1500, 'EUR'),
        ]);
        InventoryItem::create([
            'name' => 'Rosé', 'sku' => 'ROS', 'category' => 'FINISHED', 'unit' => 'bottles', 'is_for_sale' => true,
            'default_price' => Money::fromMinor(1200, 'EUR'),
        ]);
        $cork = InventoryItem::create(['name' => 'Cork', 'sku' => 'CORK', 'category' => 'RAW_MATERIAL', 'unit' => 'units']);
        RecipeItem::create(['output_id' => $recipeOnly->getKey(), 'input_id' => $cork->getKey(), 'quantity' => '1']);
        CustomerCategory::create(['name' => 'Restaurants']);
        Customer::create(['company_name' => 'Bistro', 'email' => 'bistro@example.test'])
            ->forceFill(['order_token' => str_repeat('a', 64)])
            ->save();
        $this->forgetTenant();

        $response = $this->hub($tenant, $admin)->assertOk();
        $steps = $this->stepsByKey($response);

        // Cost per unit or a recipe both count; the third wine has neither.
        $this->assertSame('in_progress', $steps['product_costs']['status']);
        $this->assertSame(['done' => 2, 'total' => 3], $steps['product_costs']['progress']);
        $this->assertSame(1, $steps['product_costs']['issues'][0]['count']);

        $this->assertSame('done', $steps['materials']['status']);
        $this->assertSame('in_progress', $steps['min_stock']['status']);
        $this->assertSame('done', $steps['customer_categories']['status']);
        $this->assertSame(1, $steps['customer_categories']['issues'][0]['count']);
        $this->assertSame('done', $steps['order_portal']['status']);
        $this->assertSame('not_started', $steps['special_prices']['status']);
        $this->assertSame('not_started', $steps['first_order']['status']);
        $this->assertSame('sell_better', $steps['first_order']['section']);

        $response->assertInertia(fn (AssertableInertia $page) => $page
            ->where('portalPreview.customer', 'Bistro')
            ->where('portalPreview.url', '/order/'.str_repeat('a', 64)));
    }

    public function test_sell_better_steps_do_not_count_towards_go_live(): void
    {
        [$tenant, $admin] = $this->tenantAndAdmin();

        // Skipping every optional "Sell better" step moves nothing on the go-live summary.
        foreach (['special_prices', 'customer_categories', 'order_portal', 'min_stock', 'materials'] as $step) {
            $this->actingAs($admin)
                ->withSession([ActiveTenantSession::KEY => $tenant->getKey()])
                ->patch("/setup/steps/{$step}", ['skipped' => true])
                ->assertRedirect();
        }

        $this->hub($tenant, $admin)->assertInertia(fn (AssertableInertia $page) => $page
            ->where('summary.done', 0)
            ->where('summary.total', 7)
            ->where('steps.9.status', 'skipped'));
    }

    public function test_product_costs_and_first_order_cannot_be_skipped(): void
    {
        [$tenant, $admin] = $this->tenantAndAdmin();

        foreach (['product_costs', 'first_order'] as $step) {
            $this->actingAs($admin)
                ->withSession([ActiveTenantSession::KEY => $tenant->getKey()])
                ->from('/setup')
                ->patch("/setup/steps/{$step}", ['skipped' => true])
                ->assertSessionHasErrors('skipped');
        }
    }
}
