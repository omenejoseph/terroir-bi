<?php

declare(strict_types=1);

namespace App\Actions\Billing;

use App\Enums\Module;
use App\Models\Plan;

/**
 * Creates a subscription plan. Module keys are validated against the Module enum
 * (unknown keys are dropped) so the stored set is always meaningful.
 */
class CreatePlanAction
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function execute(array $data): Plan
    {
        return Plan::create($this->normalize($data));
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function normalize(array $data): array
    {
        $modules = $data['modules'] ?? [];
        $data['modules'] = is_array($modules)
            ? array_values(array_filter($modules, fn ($m): bool => is_string($m) && Module::tryFrom($m) !== null))
            : [];

        return $data;
    }
}
