<?php

declare(strict_types=1);

namespace App\Queries;

use App\Models\Tenant;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;

/**
 * Tenants a platform admin should look at today: Stripe is failing to collect
 * (past_due/unpaid/incomplete) or the trial runs out within two weeks. Powers
 * the dashboard's "Needs attention" table.
 */
class ListTenantsNeedingAttentionQuery
{
    /**
     * @return Builder<Tenant>
     */
    public function builder(CarbonInterface $now, int $trialWindowDays = 14): Builder
    {
        return Tenant::query()
            ->with(['plan', 'subscription'])
            ->whereHas('subscription', function (Builder $query) use ($now, $trialWindowDays): void {
                $query->whereIn('stripe_status', PlatformDashboardQuery::PROBLEM_STATUSES)
                    ->orWhereBetween('trial_ends_at', [$now, $now->copy()->addDays($trialWindowDays)]);
            });
    }
}
