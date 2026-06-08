<?php

declare(strict_types=1);

namespace App\Services\Localization;

interface TranslationServiceInterface
{
    /**
     * The override map (key => value) for the current tenant + locale.
     *
     * @return array<string, string>
     */
    public function overrides(?string $locale = null): array;

    /**
     * Translate a key, preferring a tenant DB override over file translations.
     *
     * @param  array<string, string>  $replace
     */
    public function get(string $key, array $replace = [], ?string $locale = null): string;

    /**
     * The full merged translation map for a locale.
     *
     * @return array<string, string>
     */
    public function all(?string $locale = null): array;

    /** Bust the cached overrides for a locale (or all supported locales). */
    public function flush(?string $locale = null): void;
}
