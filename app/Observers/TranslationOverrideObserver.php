<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\TranslationOverride;
use App\Services\Localization\TranslationServiceInterface;

/**
 * Busts the cached override map whenever a tenant's overrides change.
 */
class TranslationOverrideObserver
{
    public function __construct(
        private readonly TranslationServiceInterface $translations,
    ) {}

    public function saved(TranslationOverride $override): void
    {
        $this->translations->flush($override->locale);
    }

    public function deleted(TranslationOverride $override): void
    {
        $this->translations->flush($override->locale);
    }
}
