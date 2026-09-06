<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Enums\TenantStatus;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Plan;
use App\Models\TenantSubscription;
use App\Queries\ListMostActiveTenantsQuery;
use App\Queries\ListTenantsNeedingAttentionQuery;
use App\Queries\PlatformDashboardQuery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\InteractsWithTenancy;
use Tests\TestCase;

class PlatformDashboardQueryTest extends TestCase
{
    use InteractsWithTenancy;
    use RefreshDatabase;

    private PlatformDashboardQuery $query;

    private Carbon $now;

    protected function setUp(): void
    {
        parent::setUp();
        $this->query = new PlatformDashboardQuery;
        $this->now = Carbon::parse('2026-06-15 12:00:00');
    }

    private function paidPlan(int $priceMinor = 2900, string $interval = 'month'): Plan
    {
        return Plan::create([
            'name' => 'Paid '.fake()->unique()->word(),
            'slug' => fake()->unique()->slug(2),
            'currency' => 'EUR',
            'price_minor' => $priceMinor,
            'interval' => $interval,
            'stripe_price_id' => 'price_'.fake()->unique()->lexify('????'),
        ]);
    }

    public function test_tenant_counts_by_status_and_new_this_month(): void
    {
        $this->createTenant(['status' => TenantStatus::Active]);
        $this->createTenant(['status' => TenantStatus::Trial]);
        $old = $this->createTenant(['status' => TenantStatus::Suspended]);
        $old->forceFill(['created_at' => $this->now->copy()->subMonths(3)])->save();

        $counts = $this->query->tenantCounts($this->now);

        $this->assertSame(3, $counts['total']);
        $this->assertSame(1, $counts['active']);
        $this->assertSame(1, $counts['trial']);
        $this->assertSame(1, $counts['suspended']);
        $this->assertSame(2, $counts['new_this_month']);
    }

    public function test_estimated_mrr_counts_active_subscriptions_and_normalises_yearly(): void
    {
        $monthly = $this->createTenant(['plan_id' => $this->paidPlan(2900, 'month')->getKey()]);
        TenantSubscription::create(['tenant_id' => $monthly->getKey(), 'stripe_status' => 'active']);

        $yearly = $this->createTenant(['plan_id' => $this->paidPlan(120000, 'year')->getKey()]);
        TenantSubscription::create(['tenant_id' => $yearly->getKey(), 'stripe_status' => 'active']);

        // Not billing: trialing subscription and a tenant with no subscription.
        $trialing = $this->createTenant(['plan_id' => $this->paidPlan()->getKey()]);
        TenantSubscription::create(['tenant_id' => $trialing->getKey(), 'stripe_status' => 'trialing']);
        $this->createTenant(['plan_id' => $this->paidPlan()->getKey()]);

        $mrr = $this->query->estimatedMrr();

        $this->assertSame(2900 + 10000, $mrr['minor']);
        $this->assertSame(2, $mrr['paying_tenants']);
        $this->assertSame('EUR', $mrr['currency']);
    }

    public function test_signups_per_month_buckets_and_zero_fills(): void
    {
        $this->createTenant(); // now
        $lastMonth = $this->createTenant();
        $lastMonth->forceFill(['created_at' => $this->now->copy()->subMonth()])->save();

        Carbon::setTestNow($this->now);
        try {
            $signups = $this->query->signupsPerMonth($this->now, 3);
        } finally {
            Carbon::setTestNow();
        }

        $this->assertSame(['Apr 26', 'May 26', 'Jun 26'], array_keys($signups));
        $this->assertSame(1, $signups['May 26']);
        $this->assertSame(0, $signups['Apr 26']);
    }

    public function test_tenants_by_plan_includes_unassigned(): void
    {
        $plan = $this->paidPlan();
        $this->createTenant(['plan_id' => $plan->getKey()]);
        $this->createTenant(['plan_id' => $plan->getKey()]);
        $this->createTenant();

        $byPlan = $this->query->tenantsByPlan();

        $this->assertSame(2, $byPlan[$plan->name]);
        $this->assertSame(1, $byPlan['No plan']);
    }

    public function test_order_activity_counts_across_tenants_without_context(): void
    {
        $tenantA = $this->createTenant();
        $tenantB = $this->createTenant();

        foreach ([$tenantA, $tenantB] as $tenant) {
            $admin = $this->createMember($tenant);
            $this->actingAsTenant($tenant);
            $customer = Customer::create(['company_name' => 'C '.$tenant->slug, 'email' => $tenant->slug.'@example.com']);
            Order::create([
                'order_number' => 'ORD-'.$tenant->slug,
                'customer_id' => $customer->getKey(),
                'created_by_id' => $admin->getKey(),
                'total_amount' => 1000,
            ]);
        }
        $this->forgetTenant();

        $activity = $this->query->orderActivity(Carbon::now());

        $this->assertSame(2, $activity['total']);
        $this->assertCount(30, $activity['per_day']);
        $this->assertSame(2, array_sum($activity['per_day']));
    }

    public function test_order_activity_buckets_by_day_boundary_and_excludes_orders_outside_window(): void
    {
        $days = 30;
        $now = Carbon::parse('2026-06-15 12:00:00');
        $start = $now->copy()->startOfDay()->subDays($days - 1); // 2026-05-17 00:00:00

        $tenantA = $this->createTenant();
        $tenantB = $this->createTenant();

        $this->actingAsTenant($tenantA);
        $customerA = Customer::create(['company_name' => 'A Co', 'email' => 'a-boundary@example.com']);
        $adminA = $this->createMember($tenantA);

        // One day before the window starts — must not be counted at all.
        $old = Order::create([
            'order_number' => 'ORD-OLD', 'customer_id' => $customerA->getKey(),
            'created_by_id' => $adminA->getKey(), 'total_amount' => 1000,
        ]);
        $old->forceFill(['created_at' => $start->copy()->subSecond()])->save();

        // Day boundary: 23:59:59 one day vs 00:01:00 the next must land in
        // different buckets, never merged into one.
        $lateNight = Order::create([
            'order_number' => 'ORD-LATE', 'customer_id' => $customerA->getKey(),
            'created_by_id' => $adminA->getKey(), 'total_amount' => 1000,
        ]);
        $lateNight->forceFill(['created_at' => Carbon::parse('2026-06-01 23:59:59')])->save();

        $earlyMorning = Order::create([
            'order_number' => 'ORD-EARLY', 'customer_id' => $customerA->getKey(),
            'created_by_id' => $adminA->getKey(), 'total_amount' => 1000,
        ]);
        $earlyMorning->forceFill(['created_at' => Carbon::parse('2026-06-02 00:01:00')])->save();
        $this->forgetTenant();

        // A different tenant's order, created and queried without tenant A's
        // context bound, proves the aggregate is genuinely platform-wide.
        $this->actingAsTenant($tenantB);
        $customerB = Customer::create(['company_name' => 'B Co', 'email' => 'b-boundary@example.com']);
        $adminB = $this->createMember($tenantB);
        $today = Order::create([
            'order_number' => 'ORD-TODAY', 'customer_id' => $customerB->getKey(),
            'created_by_id' => $adminB->getKey(), 'total_amount' => 1000,
        ]);
        $today->forceFill(['created_at' => $now->copy()])->save();
        $this->forgetTenant();

        // Sanity: 4 orders exist platform-wide, but only 3 fall inside the window.
        $this->assertSame(4, Order::withoutTenant()->count());

        $activity = $this->query->orderActivity($now, $days);

        $this->assertCount(30, $activity['per_day']);
        $this->assertSame(3, $activity['total']);
        $this->assertSame(3, array_sum($activity['per_day']));

        $lateIndex = (int) $start->diffInDays(Carbon::parse('2026-06-01')->startOfDay());
        $earlyIndex = (int) $start->diffInDays(Carbon::parse('2026-06-02')->startOfDay());
        $todayIndex = $days - 1;

        $this->assertNotSame($lateIndex, $earlyIndex, 'the two boundary orders must land in distinct day buckets');
        $this->assertSame(1, $activity['per_day'][$lateIndex]);
        $this->assertSame(1, $activity['per_day'][$earlyIndex]);
        $this->assertSame(1, $activity['per_day'][$todayIndex]);

        // Every other bucket (including the one holding the excluded old order) is empty.
        foreach ($activity['per_day'] as $index => $count) {
            if (! in_array($index, [$lateIndex, $earlyIndex, $todayIndex], true)) {
                $this->assertSame(0, $count, "bucket {$index} should be empty");
            }
        }
    }

    public function test_active_user_count_is_distinct_across_tenants(): void
    {
        $tenantA = $this->createTenant();
        $tenantB = $this->createTenant();
        $user = $this->createMember($tenantA);
        // Same user holds a second membership — counted once.
        $this->createMembershipFor($user, $tenantB);
        $this->createMember($tenantB);

        $this->assertSame(2, $this->query->activeUserCount());
    }

    public function test_attention_list_flags_failing_payments_and_expiring_trials(): void
    {
        $pastDue = $this->createTenant();
        TenantSubscription::create(['tenant_id' => $pastDue->getKey(), 'stripe_status' => 'past_due']);

        $trialSoon = $this->createTenant();
        TenantSubscription::create([
            'tenant_id' => $trialSoon->getKey(),
            'stripe_status' => 'trialing',
            'trial_ends_at' => $this->now->copy()->addDays(5),
        ]);

        $healthy = $this->createTenant();
        TenantSubscription::create([
            'tenant_id' => $healthy->getKey(),
            'stripe_status' => 'active',
            'trial_ends_at' => $this->now->copy()->subMonth(),
        ]);

        $ids = app(ListTenantsNeedingAttentionQuery::class)->builder($this->now)->pluck('id')->all();

        $this->assertContains($pastDue->getKey(), $ids);
        $this->assertContains($trialSoon->getKey(), $ids);
        $this->assertNotContains($healthy->getKey(), $ids);

        $this->assertSame(1, $this->query->trialsEndingSoonCount($this->now));
    }

    public function test_most_active_tenants_ranks_by_recent_orders(): void
    {
        $busy = $this->createTenant();
        $quiet = $this->createTenant();

        $admin = $this->createMember($busy);
        $this->actingAsTenant($busy);
        $customer = Customer::create(['company_name' => 'Busy bar', 'email' => 'busy@example.com']);
        foreach ([1, 2] as $n) {
            Order::create([
                'order_number' => 'ORD-B'.$n,
                'customer_id' => $customer->getKey(),
                'created_by_id' => $admin->getKey(),
                'total_amount' => 1000,
            ]);
        }
        $this->forgetTenant();

        $rows = app(ListMostActiveTenantsQuery::class)->builder(Carbon::now())->get();

        $this->assertSame($busy->getKey(), $rows->first()?->getKey());
        $this->assertSame(2, (int) $rows->first()?->getAttribute('orders_recent_count'));
        $this->assertSame(1, (int) $rows->first()?->getAttribute('members_count'));
        $this->assertSame(0, (int) $rows->firstWhere('id', $quiet->getKey())?->getAttribute('orders_recent_count'));
    }
}
