<?php

declare(strict_types=1);

namespace App\Actions\Billing;

use App\Enums\Module;
use App\Models\Plan;

/**
 * Updates a subscription plan, validating module keys against the Module enum.
 */
class UpdatePlanAction
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function execute(Plan $plan, array $data): Plan
    {
        if (array_key_exists('modules', $data)) {
            $modules = $data['modules'];
            $data['modules'] = is_array($modules)
                ? array_values(array_filter($modules, fn ($m): bool => is_string($m) && Module::tryFrom($m) !== null))
                : [];
        }

        $plan->update($data);

        return $plan;
    }
}
