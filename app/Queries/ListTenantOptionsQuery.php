<?php

declare(strict_types=1);

namespace App\Queries;

use App\Models\Tenant;

/**
 * Tenant id → name, for back-office pickers (e.g. the broadcast audience select).
 * Keeps the DB read behind a class.
 */
class ListTenantOptionsQuery
{
    /**
     * @return array<string, string>
     */
    public function options(): array
    {
        return Tenant::query()->orderBy('name')->pluck('name', 'id')->all();
    }
}
