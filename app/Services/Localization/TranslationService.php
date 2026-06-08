<?php

declare(strict_types=1);

namespace App\Services\Localization;

use App\Models\TranslationOverride;
use App\Tenancy\Contracts\TenantContext;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Contracts\Translation\Translator;

/**
 * Reads and writes per-tenant translation overrides, merged on top of the file
 * (and JSON) translations Laravel ships. Overrides are cached per tenant+locale.
 *
 * Merge precedence: DB override > file translation > the key itself.
 */
class TranslationService implements TranslationServiceInterface
{
    public function __construct(
        private readonly Cache $cache,
        private readonly TenantContext $tenant,
        private readonly Translator $translator,
    ) {}

    /**
     * The override map (key => value) for the current tenant + locale.
     *
     * Returns an empty array when no tenant is bound (e.g. central context),
     * so file translations continue to work outside a tenant.
     *
     * @return array<string, string>
     */
    public function overrides(?string $locale = null): array
    {
        if (! $this->tenant->check()) {
            return [];
        }

        $locale ??= app()->getLocale();

        return $this->cache->rememberForever(
            $this->cacheKey($this->tenant->id(), $locale),
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
        if (! $this->tenant->check()) {
            return;
        }

        $locales = $locale !== null
            ? [$locale]
            : (array) config('app.supported_locales', [config('app.locale')]);

        foreach ($locales as $loc) {
            $this->cache->forget($this->cacheKey($this->tenant->id(), $loc));
        }
    }

    private function cacheKey(string $tenantId, string $locale): string
    {
        // Manual tenant prefixing — independent of any tenancy cache bootstrapper.
        return "i18n:{$tenantId}:{$locale}";
    }

    /**
     * @param  array<string, string>  $replace
     */
    private function makeReplacements(string $line, array $replace): string
    {
        foreach ($replace as $key => $value) {
            $line = str_replace([':'.$key, ':'.ucfirst($key), ':'.strtoupper($key)], [$value, ucfirst((string) $value), strtoupper((string) $value)], $line);
        }

        return $line;
    }
}
