<?php

declare(strict_types=1);

namespace App\Queries;

use App\Models\Membership;
use App\Models\Order;
use App\Models\Tenant;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;

/**
 * Tenants ranked by recent usage (orders placed in the window), with seat and
 * last-activity context. Cross-tenant subqueries go through ->withoutTenant()
 * — the audited back-office escape hatch — because the dashboard has no tenant
 * context.
 */
class ListMostActiveTenantsQuery
{
    /**
     * @return Builder<Tenant>
     */
    public function builder(CarbonInterface $now, int $days = 30): Builder
    {
        $since = $now->copy()->startOfDay()->subDays($days - 1);

        return Tenant::query()
            ->with('plan')
            ->addSelect([
                'orders_recent_count' => Order::withoutTenant()
                    ->selectRaw('count(*)')
                    ->whereColumn('orders.tenant_id', 'tenants.id')
                    ->where('orders.created_at', '>=', $since),
                'last_order_at' => Order::withoutTenant()
                    ->selectRaw('max(created_at)')
                    ->whereColumn('orders.tenant_id', 'tenants.id'),
                // Membership is intentionally not tenant-scoped — plain query.
                'members_count' => Membership::query()
                    ->selectRaw('count(*)')
                    ->whereColumn('memberships.tenant_id', 'tenants.id'),
            ])
            ->orderByDesc('orders_recent_count');
    }
}
