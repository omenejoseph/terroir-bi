<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models\TranslationOverride;
use App\Observers\TranslationOverrideObserver;
use App\Services\Localization\TenantAwareTranslationLoader;
use App\Services\Localization\TranslationService;
use App\Services\Localization\TranslationServiceInterface;
use App\Tenancy\Adapters\Stancl\StanclTenantAdapter;
use App\Tenancy\Contracts\TenantContext;
use App\Tenancy\Contracts\TenantResolver;
use App\Tenancy\TenantManager;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Contracts\Translation\Loader;
use Illuminate\Support\ServiceProvider;

/**
 * Wires the tenancy abstraction and localization stack. This is the only place
 * application bindings reference the concrete driver adapter.
 */
class TenancyServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Tenant context + resolver (driver-backed).
        $this->app->singleton(TenantContext::class, TenantManager::class);
        $this->app->singleton(TenantResolver::class, StanclTenantAdapter::class);

        // Translation overrides service.
        $this->app->singleton(TranslationServiceInterface::class, TranslationService::class);

        // Decorate the translation loader so __() / trans() pick up DB overrides.
        $this->app->extend('translation.loader', function (Loader $loader, $app) {
            return new TenantAwareTranslationLoader(
                $loader,
                $app->make(TenantContext::class),
                $app->make(Cache::class),
            );
        });
    }

    public function boot(): void
    {
        TranslationOverride::observe(TranslationOverrideObserver::class);
    }
}
