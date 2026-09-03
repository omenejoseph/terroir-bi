<?php

declare(strict_types=1);

namespace Tests\Feature\Web;

use App\Enums\TenantRole;
use App\Models\Customer;
use App\Models\InventoryItem;
use App\Models\PricingTier;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Auth\ActiveTenantSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\InteractsWithTenancy;
use Tests\TestCase;

/**
 * The Inertia dashboard page. The numbers themselves are covered by
 * tests/Feature/Dashboard against the shared DashboardSummary service; these
 * assert the page envelope and the period selector the design specifies.
 */
class WebDashboardTest extends TestCase
{
    use InteractsWithTenancy;
    use RefreshDatabase;

    public function test_dashboard_renders_with_summary_and_filters(): void
    {
        $tenant = $this->createTenant();
        $admin = $this->createMember($tenant, [TenantRole::Admin]);

        $this->actingAs($admin)
            ->withSession([ActiveTenantSession::KEY => $tenant->getKey()])
            ->get('/dashboard')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Dashboard')
                ->has('summary.stats')
                ->has('summary.currency')
                ->has('summary.revenue_summary')
                ->has('summary.revenue_by_channel')
                ->where('filters.period', null));
    }

    /**
     * Every tab in the design's period strip (Figma 208:5577) must resolve to a
     * real window — a label with no matching token would silently fall back.
     *
     * @return list<array{0: string}>
     */
    public static function designPeriods(): array
    {
        return [['today'], ['yesterday'], ['week'], ['mtd'], ['qtd'], ['ytd']];
    }

    #[DataProvider('designPeriods')]
    public function test_each_design_period_tab_resolves(string $period): void
    {
        $tenant = $this->createTenant();
        $admin = $this->createMember($tenant, [TenantRole::Admin]);

        $this->actingAs($admin)
            ->withSession([ActiveTenantSession::KEY => $tenant->getKey()])
            ->get('/dashboard?period='.$period)
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('summary.range', $period)
                ->where('filters.period', $period));
    }

    /** The Custom tab (Figma 208:5577's 7th pill) round-trips through the same from/to the server already accepts. */
    public function test_a_custom_range_resolves_and_is_echoed_back(): void
    {
        $tenant = $this->createTenant();
        $admin = $this->createMember($tenant, [TenantRole::Admin]);

        $this->actingAs($admin)
            ->withSession([ActiveTenantSession::KEY => $tenant->getKey()])
            ->get('/dashboard?period=custom&from=2026-01-01&to=2026-01-31')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('summary.range', 'custom')
                ->where('filters.from', '2026-01-01')
                ->where('filters.to', '2026-01-31'));
    }

    /** Every card added to close the Tier 1/2 gap list actually reaches the page. */
    public function test_dashboard_carries_the_newly_wired_cards(): void
    {
        $tenant = $this->createTenant();
        $admin = $this->createMember($tenant, [TenantRole::Admin]);

        $this->actingAs($admin)
            ->withSession([ActiveTenantSession::KEY => $tenant->getKey()])
            ->get('/dashboard')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('summary.key_ratios.dtc_revenue_pct')
                ->has('summary.reorder_pipeline.total')
                ->has('summary.upcoming_tasks.due_this_week')
                ->has('summary.net_cash_flow.net')
                ->has('summary.revenue_trend', 6)
                ->where('summary.stats.ready_to_ship', 0));
    }

    /**
     * The Create Order / Customer — Create drawers (same components /orders
     * and /customers already use) read these off a partial reload. A plain
     * visit must not pay for a whole-catalog scan nobody opened a drawer for.
     */
    public function test_a_plain_visit_never_carries_the_create_drawer_options(): void
    {
        $tenant = $this->createTenant();
        $admin = $this->createMember($tenant, [TenantRole::Admin]);

        $this->actingAs($admin)
            ->withSession([ActiveTenantSession::KEY => $tenant->getKey()])
            ->get('/dashboard')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->missing('customerOptions')
                ->missing('productOptions')
                ->missing('tiers'));
    }

    /** @return array{Tenant, User} */
    private function seedOrderFormRecords(Tenant $tenant): array
    {
        $this->actingAsTenant($tenant);
        Customer::create(['company_name' => 'Konoba Riva', 'email' => 'riva@example.com']);
        InventoryItem::create([
            'name' => 'Plavac', 'sku' => 'PLV', 'category' => 'FINISHED', 'unit' => 'bottles',
            'is_active' => true, 'is_for_sale' => true, 'default_price' => 2999,
        ]);
        PricingTier::create(['name' => 'Wholesale', 'rebate_percent' => 15]);
        $this->forgetTenant();

        return [$tenant, $this->createMember($tenant, [TenantRole::Admin])];
    }

    public function test_a_member_with_orders_manage_gets_real_customer_and_product_options(): void
    {
        [$tenant, $admin] = $this->seedOrderFormRecords($this->createTenant());

        $response = $this->actingAs($admin)
            ->withSession([ActiveTenantSession::KEY => $tenant->getKey()])
            ->get('/dashboard', $this->inertiaPartial('Dashboard', ['customerOptions', 'productOptions']))
            ->assertOk();

        self::assertCount(1, $response->json('props.customerOptions'));
        self::assertCount(1, $response->json('props.productOptions'));
    }

    public function test_a_member_with_customers_manage_gets_real_tiers(): void
    {
        [$tenant, $admin] = $this->seedOrderFormRecords($this->createTenant());

        $response = $this->actingAs($admin)
            ->withSession([ActiveTenantSession::KEY => $tenant->getKey()])
            ->get('/dashboard', $this->inertiaPartial('Dashboard', 'tiers'))
            ->assertOk();

        self::assertCount(1, $response->json('props.tiers'));
    }

    /**
     * /dashboard carries no can:* route middleware (unlike /orders and
     * /customers), so these three are withheld in the closure itself rather
     * than trusting that only an authorized member would ever ask.
     */
    public function test_a_member_without_the_capability_gets_empty_options_not_data(): void
    {
        $tenant = $this->createTenant();
        [$tenant] = $this->seedOrderFormRecords($tenant);
        $employee = $this->createMember($tenant, [TenantRole::Employee]);

        $response = $this->actingAs($employee)
            ->withSession([ActiveTenantSession::KEY => $tenant->getKey()])
            ->get('/dashboard', $this->inertiaPartial('Dashboard', ['customerOptions', 'productOptions', 'tiers']))
            ->assertOk();

        self::assertSame([], $response->json('props.customerOptions'));
        self::assertSame([], $response->json('props.productOptions'));
        self::assertSame([], $response->json('props.tiers'));
    }
}
