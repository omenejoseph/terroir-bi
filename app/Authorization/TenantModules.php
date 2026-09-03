<?php

declare(strict_types=1);

namespace App\Authorization;

use App\Enums\Module;
use App\Models\Tenant;

/**
 * Resolves the module keys a tenant may use, from its plan. A tenant with no
 * plan is unrestricted (sees every module), matching EnforceModuleAccess.
 * Shared by SessionBuilder (API `/auth/me`) and HandleInertiaRequests, so the
 * API and Inertia envelopes never drift apart.
 */
final class TenantModules
{
    /** @return list<string> */
    public static function keysFor(?Tenant $tenant): array
    {
        if ($tenant === null) {
            return [];
        }

        $tenant->loadMissing('plan');

        return $tenant->plan !== null ? $tenant->plan->moduleKeys() : Module::values();
    }
}
