<?php

declare(strict_types=1);

namespace App\Actions\Tenancy;

use App\Models\Tenant;

/**
 * Updates a tenant's identity fields after creation — status and plan changes
 * have their own single-purpose Actions (UpdateTenantStatusAction,
 * AssignPlanToTenantAction); this is the same shape for the rest.
 */
class UpdateTenantDetailsAction
{
    /**
     * @param  array{name: string, slug: string, default_locale: string}  $data
     */
    public function execute(Tenant $tenant, array $data): Tenant
    {
        $tenant->name = $data['name'];
        $tenant->slug = $data['slug'];
        $tenant->default_locale = $data['default_locale'];
        $tenant->save();

        return $tenant;
    }
}
