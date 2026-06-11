<?php

declare(strict_types=1);

namespace Tests\Unit\Billing;

use App\Enums\AccessLevel;
use App\Enums\TenantStatus;
use App\Models\Plan;
use App\Models\Tenant;
use App\Models\TenantSubscription;
use App\Services\Billing\SubscriptionAccessService;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class SubscriptionAccessServiceTest extends TestCase
{
    private SubscriptionAccessService $service;

    private Carbon $now;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new SubscriptionAccessService;
        $this->now = Carbon::parse('2026-06-15 12:00:00');
    }

    private function paidPlan(): Plan
    {
        return new Plan(['stripe_price_id' => 'price_x', 'grace_full_days' => 7, 'grace_readonly_days' => 7]);
    }

    private function tenant(TenantStatus $status = TenantStatus::Active): Tenant
    {
        return new Tenant(['status' => $status]);
    }

    private function sub(string $status, ?Carbon $anchor): TenantSubscription
    {
        return new TenantSubscription([
            'stripe_status' => $status,
            'current_period_end' => $anchor,
            'trial_ends_at' => $anchor,
        ]);
    }

    public function test_active_before_period_end_is_full(): void
    {
        $sub = $this->sub('active', $this->now->copy()->addDays(10));
        $access = $this->service->compute($this->tenant(), $sub, $this->paidPlan(), $this->now);

        $this->assertSame(AccessLevel::Full, $access->level);
        $this->assertSame(10, $access->daysRemaining);
    }

    public function test_active_within_full_grace_is_full(): void
    {
        // period ended 3 days ago; grace_full is 7 → still full.
        $sub = $this->sub('active', $this->now->copy()->subDays(3));
        $access = $this->service->compute($this->tenant(), $sub, $this->paidPlan(), $this->now);

        $this->assertSame(AccessLevel::Full, $access->level);
        $this->assertSame(4, $access->daysRemaining); // 7 - 3
    }

    public function test_within_readonly_grace_is_read_only(): void
    {
        // ended 10 days ago: past full grace (7), inside read-only grace (7+7=14).
        $sub = $this->sub('active', $this->now->copy()->subDays(10));
        $access = $this->service->compute($this->tenant(), $sub, $this->paidPlan(), $this->now);

        $this->assertSame(AccessLevel::ReadOnly, $access->level);
        $this->assertSame(4, $access->daysRemaining); // 14 - 10
    }

    public function test_past_all_grace_is_blocked(): void
    {
        $sub = $this->sub('active', $this->now->copy()->subDays(20));
        $access = $this->service->compute($this->tenant(), $sub, $this->paidPlan(), $this->now);

        $this->assertSame(AccessLevel::Blocked, $access->level);
        $this->assertNull($access->daysRemaining);
    }

    public function test_trialing_runs_the_same_machine_anchored_at_trial_end(): void
    {
        $trialing = $this->service->compute($this->tenant(), $this->sub('trialing', $this->now->copy()->addDays(5)), $this->paidPlan(), $this->now);
        $this->assertSame(AccessLevel::Full, $trialing->level);

        // Trial ended 10 days ago → read-only grace.
        $lapsed = $this->service->compute($this->tenant(), $this->sub('trialing', $this->now->copy()->subDays(10)), $this->paidPlan(), $this->now);
        $this->assertSame(AccessLevel::ReadOnly, $lapsed->level);
    }

    public function test_past_due_enters_grace_immediately_from_period_end(): void
    {
        $sub = $this->sub('past_due', $this->now->copy()->subDays(2));
        $access = $this->service->compute($this->tenant(), $sub, $this->paidPlan(), $this->now);

        $this->assertSame(AccessLevel::Full, $access->level); // within 7-day full grace
    }

    public function test_canceled_is_read_only_until_ends_then_blocked(): void
    {
        $future = new TenantSubscription(['stripe_status' => 'canceled', 'ends_at' => $this->now->copy()->addDays(3)]);
        $this->assertSame(AccessLevel::ReadOnly, $this->service->compute($this->tenant(), $future, $this->paidPlan(), $this->now)->level);

        $past = new TenantSubscription(['stripe_status' => 'canceled', 'ends_at' => $this->now->copy()->subDay()]);
        $this->assertSame(AccessLevel::Blocked, $this->service->compute($this->tenant(), $past, $this->paidPlan(), $this->now)->level);
    }

    public function test_admin_suspend_overrides_everything(): void
    {
        $sub = $this->sub('active', $this->now->copy()->addDays(30));
        $access = $this->service->compute($this->tenant(TenantStatus::Suspended), $sub, $this->paidPlan(), $this->now);

        $this->assertSame(AccessLevel::Blocked, $access->level);
    }

    public function test_free_plan_is_always_full(): void
    {
        $freePlan = new Plan(['stripe_price_id' => null]);
        $access = $this->service->compute($this->tenant(), null, $freePlan, $this->now);

        $this->assertSame(AccessLevel::Full, $access->level);
        $this->assertSame('free', $access->status);
    }

    public function test_no_subscription_fails_closed_unless_trialing_tenant(): void
    {
        $this->assertSame(
            AccessLevel::Full,
            $this->service->compute($this->tenant(TenantStatus::Trial), null, $this->paidPlan(), $this->now)->level,
        );
        $this->assertSame(
            AccessLevel::Blocked,
            $this->service->compute($this->tenant(TenantStatus::Active), null, $this->paidPlan(), $this->now)->level,
        );
    }
}
