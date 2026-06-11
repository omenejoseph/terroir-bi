<?php

declare(strict_types=1);

namespace App\Actions\Tenancy;

use App\Models\Plan;
use App\Models\Tenant;

/**
 * Assigns (or clears) a tenant's plan. The plan determines the tenant's modules
 * and grace windows; the change is reflected on the next /auth/me.
 */
class AssignPlanToTenantAction
{
    public function execute(Tenant $tenant, ?Plan $plan): Tenant
    {
        $tenant->plan()->associate($plan);
        $tenant->save();

        return $tenant;
    }
}
