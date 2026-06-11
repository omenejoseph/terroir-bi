<?php

declare(strict_types=1);

namespace App\Queries;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Builder;

/**
 * Tenants for the back office, with plan + subscription eager-loaded for the
 * access-level column. Exposes the builder so Filament drives pagination/sorting
 * while the DB query stays behind a class.
 */
class ListTenantsForAdminQuery
{
    /**
     * @return Builder<Tenant>
     */
    public function builder(): Builder
    {
        return Tenant::query()->with(['plan', 'subscription']);
    }
}
