<?php

declare(strict_types=1);

namespace Tests\Feature\Web;

use App\Enums\TenantRole;
use App\Models\InventoryItem;
use App\Models\PricingTier;
use App\Models\ProductCategory;
use App\Models\StockMovement;
use App\Models\Tenant;
use App\Models\TierPrice;
use App\Models\User;
use App\Services\Auth\ActiveTenantSession;
use App\Services\Onboarding\OnboardingStatus;
use App\Support\Money\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\Concerns\InteractsWithTenancy;
use Tests\TestCase;

/**
 * Setup phase 2 — the welcome questions, the quick product grid and the
 * Pricing tiers page's price preview (docs/21-onboarding-plan.md).
 */
class WebSetupDataEntryTest extends TestCase
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

    private function asMember(User $user, Tenant $tenant): static
    {
        return $this->actingAs($user)->withSession([ActiveTenantSession::KEY => $tenant->getKey()]);
    }

    public function test_welcome_page_renders_suggestions(): void
    {
        [$tenant, $admin] = $this->tenantAndAdmin();

        $this->asMember($admin, $tenant)
            ->get('/setup/welcome')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Setup/Welcome')
                ->where('answers', null)
                ->has('wineCategories', 4)
                ->has('channelTiers.wholesale'));
    }

    public function test_welcome_answers_create_categories_and_tiers_once(): void
    {
        [$tenant, $admin] = $this->tenantAndAdmin();

        $this->actingAsTenant($tenant);
        ProductCategory::create(['type' => 'FINISHED', 'name' => 'Red']);
        PricingTier::create(['name' => 'Retail', 'rebate_percent' => '0']);
        $this->forgetTenant();

        $payload = [
            'sells' => 'wine',
            'channels' => ['wholesale', 'retail'],
            'has_file' => false,
            'categories' => [['name' => 'red', 'has_vintage' => true], ['name' => 'White', 'has_vintage' => true]],
            'tiers' => [['name' => 'Wholesale', 'rebate_percent' => 15], ['name' => 'retail', 'rebate_percent' => 0]],
        ];

        $this->asMember($admin, $tenant)->post('/setup/welcome', $payload)->assertRedirect('/setup');
        // Answering again never duplicates.
        $this->asMember($admin, $tenant)->post('/setup/welcome', $payload)->assertRedirect('/setup');

        $this->actingAsTenant($tenant);
        $this->assertSame(2, ProductCategory::query()->count());
        $this->assertTrue((bool) ProductCategory::query()->where('name', 'White')->value('has_vintage'));
        $this->assertSame(2, PricingTier::query()->count());
        $this->assertSame('15.00', PricingTier::query()->where('name', 'Wholesale')->firstOrFail()->rebate_percent);

        $answers = OnboardingStatus::state($tenant->fresh() ?? $tenant)['answers'];
        $this->assertSame(['wholesale', 'retail'], $answers['channels'] ?? null);
    }

    public function test_answering_with_a_file_goes_to_the_import(): void
    {
        [$tenant, $admin] = $this->tenantAndAdmin();

        $this->asMember($admin, $tenant)
            ->post('/setup/welcome', ['sells' => 'wine', 'has_file' => true])
            ->assertRedirect('/setup/products?setup=finished_products&import=1');
    }

    public function test_skipping_the_welcome_marks_it_seen(): void
    {
        [$tenant, $admin] = $this->tenantAndAdmin();

        $this->asMember($admin, $tenant)->post('/setup/welcome/skip')->assertRedirect('/setup');

        $this->asMember($admin, $tenant)
            ->get('/setup')
            ->assertInertia(fn (AssertableInertia $page) => $page->where('summary.welcomed', true));
    }

    public function test_welcome_is_closed_to_a_non_admin(): void
    {
        $tenant = $this->createTenant();
        $member = $this->createMember($tenant, [TenantRole::Team]);

        $this->asMember($member, $tenant)->get('/setup/welcome')->assertForbidden();
        $this->asMember($member, $tenant)->post('/setup/welcome', ['sells' => 'wine'])->assertForbidden();
    }

    public function test_quick_grid_creates_products_with_skus_and_opening_stock(): void
    {
        [$tenant, $admin] = $this->tenantAndAdmin();

        $this->actingAsTenant($tenant);
        $red = ProductCategory::create(['type' => 'FINISHED', 'name' => 'Red', 'has_vintage' => true]);
        InventoryItem::create(['name' => 'Taken', 'sku' => 'PLAVAC-MALI-2022', 'category' => 'RAW_MATERIAL', 'unit' => 'units']);
        $this->forgetTenant();

        $this->asMember($admin, $tenant)
            ->get('/setup/products')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Setup/Products')
                ->where('categories.0.label', 'Red'));

        $row = [
            'name' => 'Plavac Mali', 'product_category_id' => $red->getKey(), 'vintage' => '2022', 'unit_size' => '0.75 L',
            'sales_unit' => 'cases', 'bottles_per_case' => 6, 'default_price' => 1800, 'opening_stock' => 120, 'is_for_sale' => true,
        ];

        $this->asMember($admin, $tenant)
            ->from('/setup/products')
            ->post('/setup/products', ['rows' => [$row, ['name' => 'Plavac Mali', 'vintage' => '2022', 'sales_unit' => 'bottles', 'bottles_per_case' => 6]]])
            ->assertRedirect('/setup/products')
            ->assertSessionHasNoErrors();

        $this->actingAsTenant($tenant);
        $items = InventoryItem::query()->where('category', 'FINISHED')->orderBy('sku')->get();

        $this->assertSame(['PLAVAC-MALI-2022-2', 'PLAVAC-MALI-2022-3'], $items->pluck('sku')->all());

        $first = $items->firstWhere('sku', 'PLAVAC-MALI-2022-2');
        $this->assertNotNull($first);
        $this->assertSame('Red', $first->group);
        $this->assertSame('bottles', $first->unit);
        $this->assertSame(1800, $first->default_price?->getMinorAmount());
        $this->assertSame('120.000', (string) $first->current_stock);
        $this->assertSame(1, StockMovement::query()->where('inventory_item_id', $first->getKey())->count());
    }

    public function test_quick_grid_validates_each_row(): void
    {
        [$tenant, $admin] = $this->tenantAndAdmin();

        $this->asMember($admin, $tenant)
            ->from('/setup/products')
            ->post('/setup/products', ['rows' => [['name' => '', 'sales_unit' => 'barrels', 'bottles_per_case' => 0]]])
            ->assertSessionHasErrors(['rows.0.name', 'rows.0.sales_unit', 'rows.0.bottles_per_case']);

        $this->actingAsTenant($tenant);
        $this->assertSame(0, InventoryItem::query()->count());
    }

    public function test_pricing_tiers_page_previews_each_tiers_price(): void
    {
        [$tenant, $admin] = $this->tenantAndAdmin();

        $this->actingAsTenant($tenant);
        $wholesale = PricingTier::create(['name' => 'Wholesale', 'rebate_percent' => '10']);
        $vip = PricingTier::create(['name' => 'VIP', 'rebate_percent' => '0']);
        $wine = InventoryItem::create([
            'name' => 'Malvazija', 'sku' => 'MLV', 'category' => 'FINISHED', 'unit' => 'bottles',
            'is_for_sale' => true, 'default_price' => Money::fromMinor(2000, 'EUR'),
        ]);
        TierPrice::create(['pricing_tier_id' => $vip->getKey(), 'inventory_item_id' => $wine->getKey(), 'price' => Money::fromMinor(1500, 'EUR')]);
        $this->forgetTenant();

        $this->asMember($admin, $tenant)
            ->get('/customers/pricing-tiers')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('preview', 1)
                ->where('preview.0.list_price', 2000)
                ->where("preview.0.prices.{$wholesale->getKey()}", 1800)
                ->where("preview.0.prices.{$vip->getKey()}", 1500));
    }
}
