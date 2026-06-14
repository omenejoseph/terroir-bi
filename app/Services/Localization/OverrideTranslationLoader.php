<?php

declare(strict_types=1);

namespace App\Services\Localization;

use App\Models\TranslationOverride;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Contracts\Translation\Loader;

/**
 * Decorates Laravel's translation loader so JSON-style translations
 * (__('Some string')) are transparently overlaid with the global DB overrides
 * (managed in the back office). File-group translations (trans('group.key'))
 * pass straight through. Overrides are cached per locale.
 */
class OverrideTranslationLoader implements Loader
{
    public function __construct(
        private readonly Loader $inner,
        private readonly Cache $cache,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function load($locale, $group, $namespace = null)
    {
        $base = $this->inner->load($locale, $group, $namespace);

        // Only JSON translations (the '*' group) are overlaid with overrides.
        if ($group === '*' && ($namespace === null || $namespace === '*')) {
            return array_merge($base, $this->overrides($locale));
        }

        return $base;
    }

    public function addNamespace($namespace, $hint)
    {
        $this->inner->addNamespace($namespace, $hint);
    }

    public function addJsonPath($path)
    {
        $this->inner->addJsonPath($path);
    }

    public function namespaces()
    {
        return $this->inner->namespaces();
    }

    /**
     * @return array<string, string>
     */
    private function overrides(string $locale): array
    {
        return $this->cache->rememberForever(
            "i18n:overrides:{$locale}",
            fn (): array => TranslationOverride::query()
                ->where('locale', $locale)
                ->pluck('value', 'key')
                ->all(),
        );
    }
}
