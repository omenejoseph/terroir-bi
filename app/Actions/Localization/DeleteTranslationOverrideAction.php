<?php

declare(strict_types=1);

namespace App\Actions\Localization;

use App\Models\TranslationOverride;

class DeleteTranslationOverrideAction
{
    public function execute(string $locale, string $key): void
    {
        // delete() per-model so the observer fires and busts the cache.
        TranslationOverride::query()
            ->where('locale', $locale)
            ->where('key', $key)
            ->get()
            ->each->delete();
    }
}
