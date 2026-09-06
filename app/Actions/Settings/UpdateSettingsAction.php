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
    /** Channel keys a target can be set on — see App\Enums\CustomerType::channelKey(). "other" is a catch-all, never a real target. */
    private const CHANNELS = ['wholesale', 'retail', 'agency', 'shipshop'];

    /**
     * @param  array{name: string, default_locale: string, timezone: string, company_oib?: string|null, annual_revenue_target?: int|null, channel_revenue_targets?: array<string, int>|null, cash_on_hand?: int|null, cash_on_hand_as_of?: string|null}  $attributes
     */
    public function execute(Tenant $tenant, array $attributes): OrganizationSettingsData
    {
        return DB::transaction(function () use ($tenant, $attributes) {
            $tenant->forceFill([
                'name' => $attributes['name'],
                'default_locale' => $attributes['default_locale'],
            ])->save();

            $channelTargets = $this->channelTargets($attributes['channel_revenue_targets'] ?? null);

            $tenant->settings()->updateOrCreate(
                ['tenant_id' => $tenant->getKey()],
                [
                    'default_locale' => $attributes['default_locale'],
                    'timezone' => $attributes['timezone'],
                    'company_oib' => $attributes['company_oib'] ?? null,
                    'annual_revenue_target' => $attributes['annual_revenue_target'] ?? null,
                    'channel_revenue_targets' => $channelTargets === [] ? null : $channelTargets,
                    'cash_on_hand' => $attributes['cash_on_hand'] ?? null,
                    'cash_on_hand_as_of' => $attributes['cash_on_hand_as_of'] ?? null,
                ],
            );

            // Refresh the relation so the freshly upserted row is read, not a cached one.
            return OrganizationSettingsData::fromTenant($tenant->load('settings'));
        });
    }

    /**
     * Drops any key that isn't a real channel — a stray key could only come
     * from a hand-crafted request, not the Settings form itself.
     *
     * @param  array<string, int>|null  $targets
     * @return array<string, int>
     */
    private function channelTargets(?array $targets): array
    {
        if ($targets === null) {
            return [];
        }

        return array_intersect_key($targets, array_flip(self::CHANNELS));
    }
}
