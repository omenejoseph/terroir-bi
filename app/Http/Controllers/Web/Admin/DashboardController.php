<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\Admin;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Queries\ListMostActiveTenantsQuery;
use App\Queries\ListTenantsNeedingAttentionQuery;
use App\Queries\PlatformDashboardQuery;
use App\Support\Money\Money;
use App\Support\PerPage;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The platform-admin home — port of App\Filament\Pages\Dashboard and its 5
 * widgets. Every number still comes from PlatformDashboardQuery /
 * ListTenantsNeedingAttentionQuery / ListMostActiveTenantsQuery unchanged;
 * this controller is a thin wrapper, no new backend logic.
 */
class DashboardController extends Controller
{
    public function __invoke(Request $request, PlatformDashboardQuery $query): Response
    {
        $now = Carbon::now();

        $tenants = $query->tenantCounts($now);
        $mrr = $query->estimatedMrr();
        $activity = $query->orderActivity($now);

        return Inertia::render('Admin/Dashboard/Index', [
            'stats' => [
                'tenants' => $tenants,
                'trials_ending_soon' => $query->trialsEndingSoonCount($now),
                'mrr' => [
                    'major' => Money::fromMinor($mrr['minor'], $mrr['currency'])->toMajor(),
                    'currency' => $mrr['currency'],
                    'paying_tenants' => $mrr['paying_tenants'],
                ],
                'active_users' => $query->activeUserCount(),
                'order_activity' => $activity,
            ],
            'signups' => collect($query->signupsPerMonth($now))
                ->map(fn (int $count, string $label): array => ['label' => $label, 'value' => $count])
                ->values(),
            'tenantsByPlan' => collect($query->tenantsByPlan())
                ->map(fn (int $count, string $label): array => ['label' => $label, 'value' => $count])
                ->values(),
            'needingAttention' => $this->needingAttention($now),
            'mostActive' => $this->mostActive($request, $now),
        ]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function needingAttention(Carbon $now): array
    {
        return app(ListTenantsNeedingAttentionQuery::class)->builder($now)
            ->get()
            ->map(fn (Tenant $tenant): array => [
                'id' => $tenant->getKey(),
                'name' => $tenant->name,
                'plan_name' => $tenant->plan?->name,
                'stripe_status' => $tenant->subscription?->stripe_status,
                'trial_ends_at' => $tenant->subscription?->trial_ends_at?->toIso8601String(),
                'current_period_end' => $tenant->subscription?->current_period_end?->toIso8601String(),
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function mostActive(Request $request, Carbon $now): array
    {
        $perPage = PerPage::fromRequest($request, 5);
        $paginator = app(ListMostActiveTenantsQuery::class)->builder($now)
            ->paginate($perPage, ['*'], 'active_page');

        return [
            'data' => collect($paginator->items())
                ->map(fn (Tenant $tenant): array => [
                    'id' => $tenant->getKey(),
                    'name' => $tenant->name,
                    'plan_name' => $tenant->plan?->name,
                    'orders_recent_count' => (int) $tenant->getAttribute('orders_recent_count'),
                    'members_count' => (int) $tenant->getAttribute('members_count'),
                    'last_order_at' => $tenant->getAttribute('last_order_at'),
                ])
                ->values()
                ->all(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ];
    }
}
