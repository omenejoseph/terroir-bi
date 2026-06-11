<?php

declare(strict_types=1);

namespace App\Actions\Tenancy;

use App\Enums\TenantStatus;
use App\Models\Tenant;

/**
 * Sets a tenant's coarse lifecycle status (the admin override). SUSPENDED /
 * CANCELED block access regardless of Stripe; ACTIVE / TRIAL defer to the
 * subscription state.
 */
class UpdateTenantStatusAction
{
    public function execute(Tenant $tenant, TenantStatus $status): Tenant
    {
        $tenant->status = $status;
        $tenant->save();

        return $tenant;
    }
}
