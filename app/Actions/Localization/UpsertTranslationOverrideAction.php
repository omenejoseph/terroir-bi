<?php

declare(strict_types=1);

namespace App\Actions\Localization;

use App\DataTransferObjects\TranslationOverrideData;
use App\Models\TranslationOverride;

/**
 * Creates or updates a tenant's translation override for a (locale, key).
 *
 * Single responsibility, transport-agnostic: an API controller, a Livewire
 * component, an Inertia handler, or a console command can all invoke it and get
 * back the same DTO. The cache is busted automatically by the model observer.
 */
class UpsertTranslationOverrideAction
{
    public function execute(string $locale, string $key, string $value): TranslationOverrideData
    {
        $override = TranslationOverride::updateOrCreate(
            ['locale' => $locale, 'key' => $key],
            ['value' => $value],
        );

        return TranslationOverrideData::fromModel($override);
    }
}
