<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\DataTransferObjects\TenantAccessData;
use App\Enums\AccessLevel;
use App\Enums\TenantStatus;
use App\Models\Plan;
use App\Models\Tenant;
use App\Models\TenantSubscription;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * Pure decision: given a tenant, its (optional) subscription, its plan and the
 * current time, how much of the app may it use right now?
 *
 * The lifecycle on lapse is: full-access GRACE window → READ-ONLY grace window →
 * BLOCKED, with both window lengths configured per plan. No DB access, no
 * facades — `$now` is injected so the whole machine is unit-testable by moving
 * the clock.
 */
class SubscriptionAccessService
{
    public function compute(Tenant $tenant, ?TenantSubscription $sub, ?Plan $plan, CarbonInterface $now): TenantAccessData
    {
        // Admin override always wins (manual suspend/cancel from the back office).
        if (in_array($tenant->status, [TenantStatus::Suspended, TenantStatus::Canceled], true)) {
            return new TenantAccessData(AccessLevel::Blocked, 'suspended');
        }

        // Free / internal plans (no Stripe price) never bill → always usable.
        if ($plan === null || $plan->isFree()) {
            return new TenantAccessData(AccessLevel::Full, 'free');
        }

        // No billing record yet: trial tenants are still onboarding (full);
        // anyone else fails closed.
        if ($sub === null) {
            return $tenant->status === TenantStatus::Trial
                ? new TenantAccessData(AccessLevel::Full, 'trial')
                : new TenantAccessData(AccessLevel::Blocked, 'no_subscription');
        }

        $status = $sub->stripe_status ?? '';

        return match ($status) {
            'trialing' => $this->fromAnchor($plan, $now, $sub->trial_ends_at, $status, $sub, true),
            'active' => $this->fromAnchor($plan, $now, $sub->current_period_end, $status, $sub, true),
            'past_due', 'unpaid' => $this->fromAnchor($plan, $now, $sub->current_period_end ?? Carbon::instance($now), $status, $sub, false),
            'canceled' => $this->canceled($sub, $now),
            default => $tenant->status === TenantStatus::Trial
                ? new TenantAccessData(AccessLevel::Full, $status !== '' ? $status : 'trial')
                : new TenantAccessData(AccessLevel::Blocked, $status !== '' ? $status : 'no_subscription'),
        };
    }

    /**
     * Full while the subscription is current (before `$anchor` when `$lenient`),
     * then the grace machine measured from `$anchor`.
     */
    private function fromAnchor(Plan $plan, CarbonInterface $now, ?Carbon $anchor, string $status, TenantSubscription $sub, bool $lenient): TenantAccessData
    {
        // Missing anchor for an otherwise-current sub → be lenient (full).
        if ($anchor === null) {
            return $this->state(AccessLevel::Full, $status, $sub, null, null, null, $now);
        }

        $graceFullUntil = $anchor->copy()->addDays($plan->grace_full_days);
        $graceReadonlyUntil = $graceFullUntil->copy()->addDays($plan->grace_readonly_days);

        if ($lenient && $now->lessThanOrEqualTo($anchor)) {
            // Still current; the deadline that matters is the anchor itself.
            return $this->state(AccessLevel::Full, $status, $sub, $graceFullUntil, $graceReadonlyUntil, $anchor, $now);
        }

        if ($now->lessThanOrEqualTo($graceFullUntil)) {
            return $this->state(AccessLevel::Full, $status, $sub, $graceFullUntil, $graceReadonlyUntil, $graceFullUntil, $now);
        }

        if ($now->lessThanOrEqualTo($graceReadonlyUntil)) {
            return $this->state(AccessLevel::ReadOnly, $status, $sub, $graceFullUntil, $graceReadonlyUntil, $graceReadonlyUntil, $now);
        }

        return $this->state(AccessLevel::Blocked, $status, $sub, $graceFullUntil, $graceReadonlyUntil, null, $now);
    }

    /** Cancellation already gave notice — read-only until `ends_at`, then blocked (no fresh grace). */
    private function canceled(TenantSubscription $sub, CarbonInterface $now): TenantAccessData
    {
        if ($sub->ends_at !== null && $now->lessThanOrEqualTo($sub->ends_at)) {
            return $this->state(AccessLevel::ReadOnly, 'canceled', $sub, null, $sub->ends_at, $sub->ends_at, $now);
        }

        return $this->state(AccessLevel::Blocked, 'canceled', $sub, null, null, null, $now);
    }

    private function state(AccessLevel $level, string $status, TenantSubscription $sub, ?Carbon $graceFullUntil, ?Carbon $graceReadonlyUntil, ?Carbon $deadline, CarbonInterface $now): TenantAccessData
    {
        return new TenantAccessData(
            level: $level,
            status: $status,
            trialEndsAt: $sub->trial_ends_at,
            currentPeriodEnd: $sub->current_period_end,
            graceFullUntil: $graceFullUntil,
            graceReadonlyUntil: $graceReadonlyUntil,
            daysRemaining: $deadline !== null ? $this->daysUntil($now, $deadline) : null,
        );
    }

    /** Whole days from `$now` to `$target`, never negative. */
    private function daysUntil(CarbonInterface $now, Carbon $target): int
    {
        $seconds = $target->getTimestamp() - $now->getTimestamp();

        return $seconds <= 0 ? 0 : (int) ceil($seconds / 86400);
    }
}
