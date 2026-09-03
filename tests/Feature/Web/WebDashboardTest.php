<?php

declare(strict_types=1);

namespace Tests\Feature\Web;

use App\Enums\TenantRole;
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
}
