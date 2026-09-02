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
}
