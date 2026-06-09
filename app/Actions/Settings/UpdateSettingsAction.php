<?php

declare(strict_types=1);

namespace App\Actions\Settings;

use App\DataTransferObjects\OrganizationSettingsData;
use App\Models\Tenant;
use Illuminate\Support\Facades\DB;

/**
 * Updates organisation settings: the tenant name plus its TenantSetting row.
 * Currency is left untouched (read-only). The default locale is mirrored onto
 * the tenant so locale resolution has a single source of truth.
 */
class UpdateSettingsAction
{
    /**
     * @param  array{name: string, default_locale: string, timezone: string, company_oib?: string|null}  $attributes
     */
    public function execute(Tenant $tenant, array $attributes): OrganizationSettingsData
    {
        return DB::transaction(function () use ($tenant, $attributes) {
            $tenant->forceFill([
                'name' => $attributes['name'],
                'default_locale' => $attributes['default_locale'],
            ])->save();

            $tenant->settings()->updateOrCreate(
                ['tenant_id' => $tenant->getKey()],
                [
                    'default_locale' => $attributes['default_locale'],
                    'timezone' => $attributes['timezone'],
                    'company_oib' => $attributes['company_oib'] ?? null,
                ],
            );

            // Refresh the relation so the freshly upserted row is read, not a cached one.
            return OrganizationSettingsData::fromTenant($tenant->load('settings'));
        });
    }
}
