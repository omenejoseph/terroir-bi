<?php

declare(strict_types=1);

namespace App\Queries;

use App\Enums\MembershipStatus;
use App\Enums\TenantStatus;
use App\Models\Membership;
use App\Models\Order;
use App\Models\Plan;
use App\Models\Tenant;
use App\Models\TenantSubscription;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * Cross-tenant aggregates for the back-office dashboard. Platform-level reads,
 * so tenant-scoped tables are accessed through the audited ->withoutTenant()
 * escape hatch. Date bucketing happens in PHP to stay DB-driver agnostic.
 */
class PlatformDashboardQuery
{
    /** Stripe statuses that bill (count toward MRR). */
    private const PAYING_STATUSES = ['active'];

    /** Stripe statuses that need an admin's eye. */
    public const PROBLEM_STATUSES = ['past_due', 'unpaid', 'incomplete', 'incomplete_expired'];

    /**
     * @return array{total: int, trial: int, active: int, suspended: int, canceled: int, new_this_month: int}
     */
    public function tenantCounts(CarbonInterface $now): array
    {
        $byStatus = Tenant::query()
            ->selectRaw('status, count(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        return [
            'total' => (int) $byStatus->sum(),
            'trial' => (int) ($byStatus[TenantStatus::Trial->value] ?? 0),
            'active' => (int) ($byStatus[TenantStatus::Active->value] ?? 0),
            'suspended' => (int) ($byStatus[TenantStatus::Suspended->value] ?? 0),
            'canceled' => (int) ($byStatus[TenantStatus::Canceled->value] ?? 0),
            'new_this_month' => Tenant::query()->where('created_at', '>=', $now->copy()->startOfMonth())->count(),
        ];
    }

    /**
     * Estimated monthly recurring revenue: the plan price of every tenant whose
     * Stripe subscription is currently billing. Yearly plans count at 1/12.
     *
     * @return array{minor: int, currency: string, paying_tenants: int}
     */
    public function estimatedMrr(): array
    {
        $tenants = Tenant::query()
            ->with('plan')
            ->whereHas('subscription', fn ($q) => $q->whereIn('stripe_status', self::PAYING_STATUSES))
            ->whereHas('plan', fn ($q) => $q->whereNotNull('price_minor'))
            ->get();

        $minor = 0;
        $currency = 'EUR';

        foreach ($tenants as $tenant) {
            $plan = $tenant->plan;
            $price = $plan?->price_minor;

            if ($plan === null || $price === null) {
                continue;
            }

            $currency = $price->getCurrencyCode();
            $minor += $plan->interval === 'year'
                ? intdiv($price->getMinorAmount(), 12)
                : $price->getMinorAmount();
        }

        return ['minor' => $minor, 'currency' => $currency, 'paying_tenants' => $tenants->count()];
    }

    /**
     * New tenants per calendar month, oldest first, zero-filled.
     *
     * @return array<string, int> "Jan 26" => count
     */
    public function signupsPerMonth(CarbonInterface $now, int $months = 12): array
    {
        $start = $now->copy()->startOfMonth()->subMonths($months - 1);

        $createdAt = Tenant::query()
            ->where('created_at', '>=', $start)
            ->pluck('created_at');

        $buckets = [];
        for ($i = 0; $i < $months; $i++) {
            $buckets[$start->copy()->addMonths($i)->format('M y')] = 0;
        }

        foreach ($createdAt as $timestamp) {
            $key = Carbon::parse((string) $timestamp)->format('M y');
            if (array_key_exists($key, $buckets)) {
                $buckets[$key]++;
            }
        }

        return $buckets;
    }

    /**
     * Tenant counts per plan (unassigned tenants grouped as "No plan").
     *
     * @return array<string, int>
     */
    public function tenantsByPlan(): array
    {
        $counts = Tenant::query()
            ->selectRaw('plan_id, count(*) as aggregate')
            ->groupBy('plan_id')
            ->pluck('aggregate', 'plan_id');

        $names = Plan::query()->whereIn('id', $counts->keys()->filter())->pluck('name', 'id');

        $result = [];
        foreach ($counts as $planId => $count) {
            // A null plan_id arrives as '' once plucked into an array key.
            $label = $planId === '' ? 'No plan' : (string) ($names[$planId] ?? 'Unknown plan');
            $result[$label] = ($result[$label] ?? 0) + (int) $count;
        }

        arsort($result);

        return $result;
    }

    /**
     * Platform-wide order volume: total over the window plus a per-day series
     * (sparkline material). Orders are the strongest "is anyone using this"
     * signal the schema has.
     *
     * @return array{total: int, per_day: list<int>}
     */
    public function orderActivity(CarbonInterface $now, int $days = 30): array
    {
        $start = $now->copy()->startOfDay()->subDays($days - 1);

        $createdAt = Order::withoutTenant()
            ->where('created_at', '>=', $start)
            ->pluck('created_at');

        $perDay = array_fill(0, $days, 0);

        foreach ($createdAt as $timestamp) {
            $index = (int) $start->diffInDays(Carbon::parse((string) $timestamp)->startOfDay());
            if ($index >= 0 && $index < $days) {
                $perDay[$index]++;
            }
        }

        return ['total' => array_sum($perDay), 'per_day' => array_values($perDay)];
    }

    /** Distinct users holding at least one active membership. */
    public function activeUserCount(): int
    {
        // Membership is intentionally not tenant-scoped (see the model docblock).
        return Membership::query()
            ->where('status', MembershipStatus::Active->value)
            ->distinct()
            ->count('user_id');
    }

    /** Trials (by Stripe trial clock) ending within the window. */
    public function trialsEndingSoonCount(CarbonInterface $now, int $days = 14): int
    {
        return TenantSubscription::query()
            ->whereBetween('trial_ends_at', [$now, $now->copy()->addDays($days)])
            ->count();
    }
}
