<?php

declare(strict_types=1);

namespace App\Actions\Tenancy;

use App\Models\Plan;
use App\Models\Tenant;

/**
 * Assigns (or clears) a tenant's plan. Accepts a Plan, a plan id, or null — so
 * callers (the back office) never have to load the Plan model themselves. The
 * plan determines the tenant's modules and grace windows; the change shows on
 * the next /auth/me.
 */
class AssignPlanToTenantAction
{
    public function execute(Tenant $tenant, Plan|string|null $plan): Tenant
    {
        if ($plan === null) {
            $tenant->plan()->dissociate();
        } else {
            $tenant->plan()->associate($plan);
        }

        $tenant->save();

        return $tenant;
    }
}
