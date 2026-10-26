<?php

declare(strict_types=1);

namespace App\Actions\Onboarding;

use App\Models\Tenant;
use App\Models\TenantSetting;
use App\Services\Onboarding\OnboardingStatus;

/**
 * Writes the part of the setup checklist that real data can't express — a
 * step skipped or marked done by hand, and the dashboard reminder being
 * dismissed (see OnboardingStatus). Each call changes one thing and keeps the
 * rest of TenantSetting::$onboarding as it was.
 */
class UpdateOnboardingStateAction
{
    /** Skip or un-skip a step (only steps in OnboardingStatus::SKIPPABLE). */
    public function skip(Tenant $tenant, string $step, bool $skipped): void
    {
        $this->toggle($tenant, 'skipped', $step, $skipped);
    }

    /** Mark or unmark a step done by hand (only steps in OnboardingStatus::MARKABLE). */
    public function markDone(Tenant $tenant, string $step, bool $done): void
    {
        $this->toggle($tenant, 'done', $step, $done);
    }

    /** Hide (or bring back) the setup reminder on the dashboard. */
    public function dismiss(Tenant $tenant, bool $dismissed): void
    {
        $state = OnboardingStatus::state($tenant);
        $state['dismissed_at'] = $dismissed ? now()->toIso8601String() : null;

        $this->store($tenant, $state);
    }

    /**
     * @param  'skipped'|'done'  $list
     */
    private function toggle(Tenant $tenant, string $list, string $step, bool $on): void
    {
        $state = OnboardingStatus::state($tenant);
        $keys = array_values(array_diff($state[$list], [$step]));

        if ($on) {
            $keys[] = $step;
        }

        $state[$list] = $keys;

        $this->store($tenant, $state);
    }

    /**
     * @param  array{skipped: list<string>, done: list<string>, dismissed_at: string|null}  $state
     */
    private function store(Tenant $tenant, array $state): void
    {
        $settings = $tenant->settings;

        if (! $settings instanceof TenantSetting) {
            $settings = $tenant->settings()->create([]);
        }

        $settings->forceFill(['onboarding' => $state])->save();
        $tenant->setRelation('settings', $settings);
    }
}
