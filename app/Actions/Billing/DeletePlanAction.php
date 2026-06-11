<?php

declare(strict_types=1);

namespace App\Actions\Billing;

use App\Models\Plan;

/**
 * Deletes a plan. Tenants keep their (now-null) plan_id via the FK's nullOnDelete.
 */
class DeletePlanAction
{
    public function execute(Plan $plan): void
    {
        $plan->delete();
    }
}
