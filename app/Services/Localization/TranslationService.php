<?php

declare(strict_types=1);

namespace App\Services\Localization;

use App\Models\TranslationOverride;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Contracts\Translation\Translator;

/**
 * Reads global translation overrides, merged on top of the file (and JSON)
 * translations Laravel ships. Overrides are platform-wide (managed in the back
 * office), not per-tenant, and cached per locale.
 *
 * Merge precedence: DB override > file translation > the key itself.
 */
class TranslationService implements TranslationServiceInterface
{
    public function __construct(
        private readonly Cache $cache,
        private readonly Translator $translator,
    ) {}

    /**
     * The override map (key => value) for a locale.
     *
     * @return array<string, string>
     */
    public function overrides(?string $locale = null): array
    {
        $locale ??= app()->getLocale();

        return $this->cache->rememberForever(
            $this->cacheKey($locale),
            fn (): array => TranslationOverride::query()
                ->where('locale', $locale)
                ->pluck('value', 'key')
                ->all(),
        );
    }

    public function get(string $key, array $replace = [], ?string $locale = null): string
    {
        $locale ??= app()->getLocale();
        $overrides = $this->overrides($locale);

        if (array_key_exists($key, $overrides)) {
            return $this->makeReplacements($overrides[$key], $replace);
        }

        return (string) $this->translator->get($key, $replace, $locale);
    }

    /**
     * The full merged map (file/JSON translations overlaid with DB overrides)
     * for a locale — useful for bootstrapping a frontend.
     *
     * @return array<string, string>
     */
    public function all(?string $locale = null): array
    {
        $locale ??= app()->getLocale();

        $file = (array) $this->translator->getLoader()->load($locale, '*', '*');

        return array_merge($file, $this->overrides($locale));
    }

    public function flush(?string $locale = null): void
    {
        $locales = $locale !== null
            ? [$locale]
            : (array) config('app.supported_locales', [config('app.locale')]);

        foreach ($locales as $loc) {
            $this->cache->forget($this->cacheKey($loc));
        }
    }

    private function cacheKey(string $locale): string
    {
        return "i18n:overrides:{$locale}";
    }

    /**
     * @param  array<string, string>  $replace
     */
    private function makeReplacements(string $line, array $replace): string
    {
        $pairs = [];

        foreach ($replace as $key => $value) {
            $pairs[':'.$key] = (string) $value;
            $pairs[':'.ucfirst($key)] = ucfirst((string) $value);
            $pairs[':'.strtoupper($key)] = strtoupper((string) $value);
        }

        // strtr() (unlike sequential str_replace calls) matches the longest
        // key first at each position, so a placeholder that is a literal
        // prefix of another (":to" inside ":total") cannot clip it into
        // garbage — see interpolate() in resources/js/composables/useTranslations.ts
        // for the client-side mirror of this same fix.
        return strtr($line, $pairs);
    }
}
